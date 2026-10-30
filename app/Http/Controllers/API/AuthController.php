<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\RegisterRequest;
use App\Mail\RegistrationVerificationCode;
use App\Models\Company;
use App\Models\User;
use App\Models\UserSetting;
use App\Support\ActivityLogger;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

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
     * Champs financiers GLOBAUX du compte masqués dans GET /api/user pour
     * un membre sans wallet.view_balance — voir user() plus bas.
     */
    private const HIDDEN_FINANCIAL_FIELDS = [
        'balance',
        'total_sale',
        'total_expense',
        'calculated_wallet',
        'wallet',
        'total_deposit',
        'total_withdrawal',
        'company_share',
    ];

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
     * Step 2 de l'inscription : vérifie le code à 6 chiffres et crée
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

        // Même représentation que login et GET /api/user.
        return response()->json($this->userPayload($user), 201);
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
     * Génère un code de vérification à 6 chiffres (avec zéros non significatifs).
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
        
        // Chantier "Équipe" — 5 tentatives par identifiant+IP sur 15 minutes
        // avant verrouillage temporaire, qu'il s'agisse d'un propriétaire
        // (email) ou d'un membre (identifiant). Clé composite pour qu'un
        // attaquant changeant d'IP ne contourne pas la limite sur un
        // identifiant donné, et inversement.
        $rateLimitKey = 'login:' . strtolower($loginField) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'success' => false,
                'code' => 'TOO_MANY_ATTEMPTS',
                'message' => 'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
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
            // Décompte des 15 minutes posé au PREMIER échec seulement (Laravel
            // ne le ré-étend pas tant qu'il n'a pas expiré) — message
            // volontairement générique, jamais "email inconnu" vs "mot de
            // passe incorrect" (évite de révéler quels identifiants existent).
            RateLimiter::hit($rateLimitKey, 900);

            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        RateLimiter::clear($rateLimitKey);

        // Un membre suspendu est refusé IMMÉDIATEMENT, avant toute autre
        // vérification — jamais de message différent selon la raison
        // (sécurité : ne pas confirmer/infirmer l'existence du compte).
        if ($user->isMember() && $user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'code' => 'ACCOUNT_SUSPENDED',
                'message' => 'Votre accès a été suspendu. Contactez le responsable.',
            ], 403);
        }

        // La vérification d'email ne concerne JAMAIS un membre (identifiant =
        // username, pas email — un membre peut même n'avoir aucun email) —
        // voir TeamService::createMemberAccount() qui pose déjà
        // email_verified_at=now() à la création, cette garde est une
        // seconde ligne de défense explicite.
        if ($user->isOwner() && !$user->email_verified_at) {
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

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->isMember()) {
            ActivityLogger::log($user->id, 'team.member_login');
        }

        // Même représentation que GET /api/user (permissions, my_day, champs
        // financiers masqués pour un caissier…).
        return response()->json($this->userPayload($user));
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

        return response()->json($this->userPayload($user));
    }

    /**
     * Représentation UNIQUE de l'utilisateur connecté — renvoyée par
     * GET /api/user ET par POST /api/login. Avant, la connexion renvoyait
     * le modèle brut : sans permissions ni my_day (le front affichait le
     * tableau de bord du gérant à un caissier jusqu'au rechargement) et AVEC
     * les champs financiers globaux qu'un caissier ne doit jamais recevoir.
     */
    private function userPayload(User $user): array
    {
        // Charger l'entreprise/les paramètres DU COMPTE — un membre n'a pas
        // sa propre entreprise ni ses propres paramètres, il utilise ceux du
        // propriétaire (voir User::accountId()).
        $accountId = $user->accountId();
        $user->setRelation('company', Company::where('user_id', $accountId)->first());
        $user->setRelation('settings', UserSetting::where('user_id', $accountId)->first());

        $payload = $user->toArray();

        // Totaux du COMPTE (articles, stock, ventes, solde…) : les accesseurs
        // de User se calculent sur SES propres lignes (user_id = son id) —
        // pour un membre, ce serait toujours 0 (valeur du stock à 0 pour le
        // gestionnaire de stock, solde à 0 pour le gérant). On reprend ceux
        // du propriétaire ; les champs financiers restent masqués plus bas
        // pour qui n'a pas wallet.view_balance.
        if ($user->isMember()) {
            $account = User::find($accountId);
            if ($account) {
                $account->setRelation('settings', $user->settings);
                foreach ($account->getAppends() as $field) {
                    $payload[$field] = $account->{$field};
                }
            }
        }

        $payload['is_owner'] = $user->isOwner();
        $payload['permissions'] = Permissions::effectivePermissions($user);

        // Un membre sans wallet.view_balance (ex. caissier) ne doit jamais
        // voir la position financière GLOBALE du compte — à la place, SA
        // caisse du jour (chantier "Équipe" étape 2 — voir myDay()). Le
        // propriétaire et tout membre ayant la permission (manager,
        // comptable) gardent les champs financiers globaux inchangés et
        // n'ont pas besoin de my_day (toujours null pour eux).
        if (!Permissions::userHas($user, Permissions::WALLET_VIEW_BALANCE)) {
            foreach (self::HIDDEN_FINANCIAL_FIELDS as $field) {
                unset($payload[$field]);
            }
            $payload['my_day'] = $this->myDay($user);
        } else {
            $payload['my_day'] = null;
        }

        return $payload;
    }

    /**
     * "Caisse du jour" d'UN membre — ses propres ventes d'aujourd'hui
     * (heure d'Abidjan), remplace les champs financiers globaux masqués
     * ci-dessus pour qui n'a pas wallet.view_balance.
     */
    private function myDay(User $user): array
    {
        $timezone = 'Africa/Abidjan';
        $start = \Illuminate\Support\Carbon::now($timezone)->startOfDay();
        $end = \Illuminate\Support\Carbon::now($timezone)->endOfDay();

        $query = fn () => \App\Models\Operation::where('user_id', $user->accountId())
            ->where('created_by_user_id', $user->id)
            ->where('type', 'sale')
            ->whereBetween('occurred_at', [$start, $end]);

        $salesCount = $query()->count();
        $total = (float) $query()->sum('total_amount');

        $byPaymentMethod = $query()
            ->selectRaw("COALESCE(payment_method, 'non_precise') as payment_method, SUM(total_amount) as amount, COUNT(*) as count")
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'amount' => round((float) $row->amount, 2),
                'count' => (int) $row->count,
            ])
            ->values();

        $lastSales = $query()
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get(['id', 'code', 'total_amount', 'payment_method', 'occurred_at'])
            ->map(fn ($operation) => [
                'id' => $operation->id,
                'code' => $operation->code,
                'total_amount' => (float) $operation->total_amount,
                'payment_method' => $operation->payment_method,
                'occurred_at' => $operation->occurred_at,
            ])
            ->values();

        // Ses dépenses du jour (payées depuis sa caisse) → solde du jour =
        // ce qu'il a encaissé − ce qu'il a dépensé. Jamais le solde global.
        $expenses = \App\Models\Operation::where('user_id', $user->accountId())
            ->where('created_by_user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('occurred_at', [$start, $end]);
        $expensesTotal = (float) (clone $expenses)->sum('total_amount');

        return [
            'sales_count' => $salesCount,
            'total' => round($total, 2),
            'expenses_count' => (clone $expenses)->count(),
            'expenses_total' => round($expensesTotal, 2),
            'balance' => round($total - $expensesTotal, 2),
            'by_payment_method' => $byPaymentMethod,
            'last_sales' => $lastSales,
        ];
    }
}
