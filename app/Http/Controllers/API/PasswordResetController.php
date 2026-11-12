<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

/**
 * Mot de passe oublié, même principe que l'inscription : un code à 6
 * chiffres envoyé par email (10 min, 5 essais), puis le nouveau mot de passe.
 *
 * Réservé aux propriétaires : un membre se connecte par identifiant, c'est
 * le responsable qui réinitialise son code (TeamController::resetCode).
 * La réponse de forgot() est toujours la même, que l'email existe ou non,
 * pour ne pas révéler quels comptes existent.
 */
class PasswordResetController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $email = strtolower($request->email);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $user->isOwner()) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            Cache::put($this->key($email), [
                'code_hash' => hash('sha256', $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)->timestamp,
            ], now()->addMinutes(self::CODE_TTL_MINUTES + 5));

            $firstName = (string) $user->first_name;
            dispatch(function () use ($user, $code, $firstName) {
                try {
                    Mail::to($user->email)->send(new PasswordResetCode($code, $firstName));
                } catch (\Throwable $e) {
                    Log::error('Échec envoi email de réinitialisation', [
                        'email' => $user->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte existe pour cet email, un code vient de vous être envoyé.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $email = strtolower($request->email);
        $key = $this->key($email);
        $pending = Cache::get($key);

        if (!$pending || now()->timestamp > $pending['expires_at']) {
            Cache::forget($key);

            return response()->json([
                'success' => false,
                'code' => 'CODE_EXPIRED',
                'message' => 'Le code a expiré. Veuillez redemander un code.',
            ], 410);
        }

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return response()->json([
                'success' => false,
                'code' => 'TOO_MANY_ATTEMPTS',
                'message' => 'Trop de tentatives incorrectes. Veuillez redemander un code.',
            ], 429);
        }

        if (!hash_equals($pending['code_hash'], hash('sha256', (string) $request->code))) {
            $pending['attempts']++;
            Cache::put($key, $pending, now()->addMinutes(self::CODE_TTL_MINUTES + 5));

            return response()->json([
                'success' => false,
                'code' => 'INVALID_CODE',
                'remaining' => self::MAX_ATTEMPTS - $pending['attempts'],
                'message' => 'Code incorrect.',
            ], 422);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        Cache::forget($key);

        if (!$user || !$user->isOwner()) {
            return response()->json([
                'success' => false,
                'code' => 'CODE_EXPIRED',
                'message' => 'Le code a expiré. Veuillez redemander un code.',
            ], 410);
        }

        $user->password = Hash::make($request->password);
        // Le code reçu prouve que l'email lui appartient.
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        // Déconnecte les autres appareils : l'ancien mot de passe a pu fuiter.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe mis à jour. Vous pouvez vous connecter.',
        ]);
    }

    private function key(string $email): string
    {
        return 'password_reset:' . $email;
    }
}
