<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChanged;
use App\Mail\PasswordResetCode as PasswordResetCodeMail;
use App\Models\ActivityLog;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Mot de passe oublié, en trois appels :
 *   1. forgot  { email }        → envoie un code à 6 chiffres (15 min, 5 essais) ;
 *   2. verify  { email, code }  → échange le code contre un jeton à usage unique (15 min) ;
 *   3. reset   { email, reset_token, password, password_confirmation }
 *      → change le mot de passe, ferme toutes les sessions et connecte l'utilisateur.
 *
 * Rien ne révèle si un compte existe : forgot répond toujours pareil, et tout
 * le travail (recherche du compte, envoi) se fait après la réponse, pour que
 * le temps de réponse soit le même. Seuls les propriétaires reçoivent un code :
 * un membre d'équipe n'a pas d'e-mail, son responsable régénère son code.
 */
class PasswordResetController extends Controller
{
    public const CODE_TTL_MINUTES = 15;

    public const TOKEN_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    /** Envois de code par e-mail et par heure. */
    public const MAX_SENDS_PER_EMAIL = 3;

    /** Demandes par adresse IP et par heure (tous e-mails confondus). */
    public const MAX_SENDS_PER_IP = 10;

    public function forgot(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $email = Str::lower(trim($request->email));
        $emailKey = 'password-forgot:email:' . sha1($email);
        $ipKey = 'password-forgot:ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($emailKey, self::MAX_SENDS_PER_EMAIL)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_SENDS_PER_IP)) {
            return $this->error('TOO_MANY_REQUESTS', 'too_many_requests', 429);
        }

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $fallbackLocale = app()->getLocale();
        $this->afterResponse(fn () => $this->sendCode($email, $fallbackLocale));

        return response()->json([
            'success' => true,
            'message' => __('password_reset.sent'),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $pending = $this->pendingFor($request->email);

        if (!$pending || !$pending->code_hash || $pending->code_expires_at?->isPast()) {
            $pending?->delete();

            return $this->error('CODE_EXPIRED', 'code_expired', 410);
        }

        if ($pending->attempts >= self::MAX_ATTEMPTS) {
            $pending->delete();

            return $this->error('TOO_MANY_ATTEMPTS', 'too_many_attempts', 429);
        }

        if (!hash_equals($pending->code_hash, PasswordResetCode::hashSecret((string) $request->code))) {
            $pending->increment('attempts');

            return response()->json([
                'success' => false,
                'code' => 'INVALID_CODE',
                'remaining' => max(0, self::MAX_ATTEMPTS - $pending->attempts),
                'message' => __('password_reset.code_invalid'),
            ], 422);
        }

        // Le code ne sert qu'une fois : il est remplacé par le jeton.
        $token = Str::random(64);
        $pending->update([
            'code_hash' => null,
            'code_expires_at' => null,
            'reset_token_hash' => PasswordResetCode::hashSecret($token),
            'reset_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);

        return response()->json([
            'success' => true,
            'reset_token' => $token,
            'expires_in' => self::TOKEN_TTL_MINUTES * 60,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);
        $request->validate([
            'email' => ['required', 'email'],
            'reset_token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $pending = $this->pendingFor($request->email);

        if (!$pending
            || !$pending->reset_token_hash
            || $pending->reset_token_expires_at?->isPast()
            || !hash_equals($pending->reset_token_hash, PasswordResetCode::hashSecret((string) $request->reset_token))) {
            return $this->error('TOKEN_INVALID', 'token_invalid', 410);
        }

        /** @var User $user */
        $user = $pending->user;

        DB::transaction(function () use ($user, $pending) {
            $user->password = Hash::make(request('password'));
            // Le code reçu prouve que l'adresse lui appartient ; un nouveau
            // remember_token invalide les cookies « Se souvenir de moi ».
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            $pending->delete();
            $user->tokens()->delete();
            // Sessions en base (SESSION_DRIVER=database) : toutes fermées.
            $sessions = config('session.table') ?: 'sessions';
            if (Schema::hasTable($sessions)) {
                DB::table($sessions)->where('user_id', $user->id)->delete();
            }

            ActivityLog::create([
                'account_id' => $user->id,
                'user_id' => $user->id,
                'action' => 'auth.password_reset',
                'details' => ['ip' => request()->ip()],
            ]);
        });

        $locale = $this->localeFor($user, app()->getLocale());
        $changedAt = now();
        $this->afterResponse(function () use ($user, $locale, $changedAt) {
            try {
                Mail::to($user->email)->locale($locale)->send(new PasswordChanged((string) $user->first_name, $changedAt));
            } catch (\Throwable $e) {
                Log::error('Échec envoi email de confirmation de mot de passe', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        });

        // Nouvelle session pour cet appareil, comme après l'inscription.
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return app(AuthController::class)->user($request);
    }

    /** Exécuté après la réponse : rien de ce qui suit ne change ce que voit le client. */
    private function sendCode(string $email, string $fallbackLocale): void
    {
        $user = $this->ownerByEmail($email);

        if (!$user || $user->status === 'suspended') {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Une seule demande par compte : un nouveau code annule l'ancien (et son jeton).
        PasswordResetCode::updateOrCreate(['user_id' => $user->id], [
            'code_hash' => PasswordResetCode::hashSecret($code),
            'attempts' => 0,
            'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
        ]);

        $provider = in_array($user->provider, ['google', 'facebook'], true) ? ucfirst($user->provider) : null;

        try {
            Mail::to($user->email)
                ->locale($this->localeFor($user, $fallbackLocale))
                ->send(new PasswordResetCodeMail($code, (string) $user->first_name, self::CODE_TTL_MINUTES, $provider));
        } catch (\Throwable $e) {
            Log::error('Échec envoi email de réinitialisation', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Après la réponse HTTP (pas de worker de queue en production), une seule
     * fois : les rappels de fin de requête restent enregistrés sur
     * l'application, qui sert plusieurs requêtes de suite dans les tests.
     */
    private function afterResponse(\Closure $callback): void
    {
        $done = false;
        app()->terminating(function () use (&$done, $callback) {
            if ($done) {
                return;
            }
            $done = true;
            $callback();
        });
    }

    private function ownerByEmail(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->whereNull('account_id')
            ->first();
    }

    private function pendingFor(string $email): ?PasswordResetCode
    {
        $user = $this->ownerByEmail($email);

        return $user ? PasswordResetCode::where('user_id', $user->id)->first() : null;
    }

    /** Langue du compte si elle est réglée, sinon celle de la requête. */
    private function localeFor(User $user, string $fallback): string
    {
        $language = $user->settings()->value('language');

        return in_array($language, ['fr', 'en'], true) ? $language : $fallback;
    }

    private function useRequestLocale(Request $request): void
    {
        app()->setLocale($request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
    }

    private function error(string $code, string $messageKey, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => __('password_reset.' . $messageKey),
        ], $status);
    }
}
