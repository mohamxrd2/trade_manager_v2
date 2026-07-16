<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\RegisterRequest;
use App\Mail\RegistrationVerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    /**
     * Durée de validité du code de vérification (minutes).
     */
    private const CODE_TTL_MINUTES = 10;

    /**
     * Nombre maximum de tentatives incorrectes avant invalidation du code.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Step 1 de l'inscription : valide les données, génère un code à 5
     * chiffres, stocke l'inscription en attente (avec le mot de passe déjà
     * hashé) dans le cache, et envoie le code par email.
     *
     * Le compte User n'est PAS créé à cette étape — seulement après
     * vérification du code via verifyRegistration().
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $email = strtolower($request->email);
        $code = $this->generateCode();

        Cache::put($this->pendingRegistrationKey($email), [
            'mode' => 'register',
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'username' => $request->username,
            'company_share' => $request->company_share ?? 100.00,
            'profile_image' => $request->profile_image,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'code' => $code,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)->timestamp,
        ], now()->addMinutes(self::CODE_TTL_MINUTES + 5));

        if (!$this->sendVerificationCode($request->email, $code, $request->first_name)) {
            return response()->json([
                'success' => false,
                'message' => "L'envoi de l'email de vérification a échoué. Veuillez réessayer.",
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Un code de vérification a été envoyé à votre adresse email.',
            'data' => [
                'email' => $request->email,
                'expires_in' => self::CODE_TTL_MINUTES * 60,
            ],
        ]);
    }

    /**
     * Step 2 de l'inscription : vérifie le code à 5 chiffres et crée
     * réellement le compte si tout correspond.
     */
    public function verifyRegistration(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $email = strtolower($request->email);
        $key = $this->pendingRegistrationKey($email);
        $pending = Cache::get($key);

        if (!$pending) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune inscription en attente pour cet email. Veuillez vous réinscrire.',
            ], 404);
        }

        if (now()->timestamp > $pending['expires_at']) {
            Cache::forget($key);

            return response()->json([
                'success' => false,
                'message' => 'Le code a expiré. Veuillez redemander un code.',
            ], 410);
        }

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return response()->json([
                'success' => false,
                'message' => 'Trop de tentatives incorrectes. Veuillez redemander un code.',
            ], 429);
        }

        if (!hash_equals($pending['code'], (string) $request->code)) {
            $pending['attempts']++;
            Cache::put($key, $pending, now()->addMinutes(self::CODE_TTL_MINUTES + 5));

            return response()->json([
                'success' => false,
                'message' => 'Code incorrect. ' . (self::MAX_ATTEMPTS - $pending['attempts']) . ' tentative(s) restante(s).',
            ], 422);
        }

        if (($pending['mode'] ?? 'register') === 'login_verification') {
            // Cas d'un compte déjà existant (créé avant l'ajout de cette
            // vérification, ou jamais vérifié) qui vient d'essayer de se
            // connecter : on le marque simplement comme vérifié.
            $user = User::find($pending['user_id']);

            if (!$user) {
                Cache::forget($key);

                return response()->json([
                    'success' => false,
                    'message' => 'Compte introuvable. Veuillez vous réinscrire.',
                ], 404);
            }
        } else {
            $user = User::create([
                'first_name' => $pending['first_name'],
                'last_name' => $pending['last_name'],
                'username' => $pending['username'],
                'company_share' => $pending['company_share'],
                'profile_image' => $pending['profile_image'],
                'email' => $pending['email'],
                'password' => $pending['password'],
            ]);
        }

        // email_verified_at n'est volontairement pas mass-assignable
        // (protection contre l'injection via un autre endpoint) : on le
        // fixe explicitement ici, seul point du code autorisé à le faire.
        $user->forceFill(['email_verified_at' => now()])->save();

        Cache::forget($key);

        // Connexion automatique après l'inscription
        Auth::guard('web')->login($user);

        // Régénération de la session pour la sécurité
        $request->session()->regenerate();

        // Retourner directement l'utilisateur (format simplifié, comme login)
        return response()->json($user, 201);
    }

    /**
     * Renvoie un nouveau code de vérification pour une inscription en attente.
     */
    public function resendRegistrationCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower($request->email);
        $key = $this->pendingRegistrationKey($email);
        $pending = Cache::get($key);

        if (!$pending) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune inscription en attente pour cet email. Veuillez vous réinscrire.',
            ], 404);
        }

        $code = $this->generateCode();
        $pending['code'] = $code;
        $pending['attempts'] = 0;
        $pending['expires_at'] = now()->addMinutes(self::CODE_TTL_MINUTES)->timestamp;

        Cache::put($key, $pending, now()->addMinutes(self::CODE_TTL_MINUTES + 5));

        if (!$this->sendVerificationCode($pending['email'], $code, $pending['first_name'])) {
            return response()->json([
                'success' => false,
                'message' => "L'envoi de l'email de vérification a échoué. Veuillez réessayer.",
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Un nouveau code de vérification a été envoyé.',
            'data' => [
                'email' => $pending['email'],
                'expires_in' => self::CODE_TTL_MINUTES * 60,
            ],
        ]);
    }

    /**
     * Génère un code de vérification à 5 chiffres (avec zéros non significatifs).
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Envoie l'email de vérification. Retourne false (au lieu de laisser
     * l'exception remonter) si l'envoi échoue, pour ne jamais exposer les
     * détails internes du transport mail au client.
     */
    private function sendVerificationCode(string $email, string $code, string $firstName): bool
    {
        try {
            Mail::to($email)->send(new RegistrationVerificationCode($code, $firstName));

            return true;
        } catch (\Throwable $e) {
            Log::error('Échec envoi email de vérification', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clé de cache pour l'inscription en attente d'un email donné.
     */
    private function pendingRegistrationKey(string $email): string
    {
        return 'pending_registration:' . $email;
    }

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        // Validation : accepter soit 'email', soit 'username', soit 'login'
        $request->validate([
            'login' => 'sometimes|string',
            'email' => 'sometimes|email',
            'username' => 'sometimes|string',
            'password' => 'required|string',
        ]);

        $password = $request->input('password');
        $remember = $request->boolean('remember', false);

        // Déterminer le champ de connexion (email ou username)
        // Priorité: login > email > username
        $loginField = $request->input('login') ?? $request->input('email') ?? $request->input('username');
        
        // Vérifier qu'au moins un champ de connexion est fourni
        if (!$loginField) {
            return response()->json(['message' => 'Email, username ou login requis'], 422);
        }
        
        // Vérifier si c'est un email ou un username
        $isEmail = filter_var($loginField, FILTER_VALIDATE_EMAIL);
        
        // Rechercher l'utilisateur par email ou username
        $user = null;
        if ($isEmail) {
            $user = User::where('email', $loginField)->first();
        } else {
            $user = User::where('username', $loginField)->first();
        }

        // Vérifier si l'utilisateur existe et si le mot de passe est correct
        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        // Email non vérifié (compte créé avant cette fonctionnalité, ou
        // jamais vérifié) : on bloque la connexion et on renvoie un code.
        if (!$user->email_verified_at) {
            $code = $this->generateCode();

            Cache::put($this->pendingRegistrationKey(strtolower($user->email)), [
                'mode' => 'login_verification',
                'user_id' => $user->id,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'code' => $code,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)->timestamp,
            ], now()->addMinutes(self::CODE_TTL_MINUTES + 5));

            if (!$this->sendVerificationCode($user->email, $code, $user->first_name)) {
                return response()->json([
                    'success' => false,
                    'message' => "L'envoi de l'email de vérification a échoué. Veuillez réessayer.",
                ], 502);
            }

            return response()->json([
                'success' => false,
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Votre email n\'est pas vérifié. Un code de vérification vient de vous être envoyé.',
                'data' => [
                    'email' => $user->email,
                    'expires_in' => self::CODE_TTL_MINUTES * 60,
                ],
            ], 403);
        }

        // Connexion de l'utilisateur avec le guard 'web'
        Auth::guard('web')->login($user, $remember);

        // Régénération de la session pour la sécurité
        $request->session()->regenerate();

        // Retourner directement l'utilisateur (format simplifié)
        return response()->json($user);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        // Déconnexion avec le guard 'web'
        Auth::guard('web')->logout();

        // Invalidation de la session
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Charger les relations nécessaires
        $user->load(['company', 'settings']);

        // Retourner directement l'utilisateur (format simplifié)
        return response()->json($user);
    }
}
