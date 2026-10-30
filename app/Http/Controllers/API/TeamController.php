<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Collaborator;
use App\Models\Operation;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TeamService;
use App\Support\ActivityLogger;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use App\Support\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Chantier "Équipe" étape 2 — endpoint UNIFIÉ qui gère à la fois les
 * ASSOCIÉS (table collaborators, part dans l'entreprise) et les MEMBRES
 * (accès applicatif, table users.account_id) comme une seule et même
 * notion de "personne de l'équipe" : une personne peut être l'un, l'autre,
 * les deux, ou ni l'un ni l'autre (le propriétaire).
 *
 * Distinct de CollaboratorController (/api/collaborators, conservé pour
 * compatibilité — voir App\Services\TeamService, le service PARTAGÉ par les
 * deux contrôleurs) : celui-ci ne connaît que les parts, celui-ci connaît
 * la personne dans son ensemble.
 *
 * Identifiant d'une personne dans {id} : l'id du compte d'ACCÈS
 * (users.id) si elle en a un, sinon l'id de la ligne ASSOCIÉ
 * (collaborators.id) — voir resolvePerson(). Le propriétaire n'est JAMAIS
 * gérable depuis cet endpoint (ni modifiable, ni suspendable, ni
 * supprimable) : son id de compte est reconnu explicitement et rejeté.
 */
class TeamController extends Controller
{
    private const ROLE_LABELS = [
        'manager' => ['fr' => 'Gérant', 'en' => 'Manager'],
        'cashier' => ['fr' => 'Caissier', 'en' => 'Cashier'],
        'stock_manager' => ['fr' => 'Gestionnaire de stock', 'en' => 'Stock manager'],
        'accountant' => ['fr' => 'Comptable', 'en' => 'Accountant'],
    ];

    /**
     * GET /api/team — le propriétaire en premier, puis chaque associé
     * et/ou membre (jamais une personne listée deux fois quand elle est
     * les deux à la fois).
     */
    public function index(): JsonResponse
    {
        $accountId = CurrentAccount::id();
        $owner = User::find($accountId);

        $people = [$this->personPayload($accountId, $owner, null, true)];

        $collaborators = Collaborator::where('user_id', $accountId)->orderBy('created_at')->get();
        $linkedMemberIds = $collaborators->pluck('user_id_member')->filter()->values()->all();

        foreach ($collaborators as $collaborator) {
            $accessUser = $collaborator->user_id_member ? User::find($collaborator->user_id_member) : null;
            $people[] = $this->personPayload($accountId, $accessUser, $collaborator);
        }

        $pureMembers = User::where('account_id', $accountId)
            ->whereNotIn('id', $linkedMemberIds)
            ->orderBy('created_at')
            ->get();

        foreach ($pureMembers as $member) {
            $people[] = $this->personPayload($accountId, $member, null);
        }

        return response()->json(['success' => true, 'data' => $people]);
    }

    /**
     * POST /api/team — ajoute une personne : associée et/ou membre (au
     * moins l'un des deux). Transaction unique ; les identifiants de
     * connexion (si has_access) ne sont renvoyés qu'ICI, une seule fois.
     */
    public function store(Request $request): JsonResponse
    {
        $accountId = CurrentAccount::id();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            // unique:users,email SANS filtre de compte : la colonne est unique sur
            // TOUTE la table (contrainte globale, un même email ne peut pas servir
            // deux fois de login) — pas seulement au sein de ce compte. Sans cette
            // règle, un email déjà pris remonte en 500 (violation de contrainte SQL
            // brute) au lieu d'un 422 propre.
            'email' => 'nullable|email|max:255|unique:users,email',
            'photo' => 'nullable|string|max:255',
            'is_partner' => 'sometimes|boolean',
            'share' => 'required_if:is_partner,true|nullable|numeric|min:0.01|max:99.99',
            'has_access' => 'sometimes|boolean',
            'role' => 'required_if:has_access,true|nullable|string|in:' . implode(',', array_diff(Permissions::ROLES, ['owner'])),
            'permissions_overrides' => 'sometimes|array',
            'permissions_overrides.*.permission' => 'required_with:permissions_overrides|string|in:' . implode(',', Permissions::ALL),
            'permissions_overrides.*.granted' => 'required_with:permissions_overrides|boolean',
        ], [
            // Le reste des messages vient de Laravel (APP_LOCALE=en, tant que le
            // backend n'a pas de lang/fr — tâche distincte) ; celui-ci est la
            // seule règle ajoutée pour ce correctif, autant qu'il parle français
            // comme tout le reste de ce contrôleur.
            'email.unique' => 'Cet email est déjà utilisé par un autre compte.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $validator->errors()], 422);
        }

        $isPartner = $request->boolean('is_partner');
        $hasAccess = $request->boolean('has_access');

        if (!$isPartner && !$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'La personne doit être associée et/ou avoir un accès',
            ], 422);
        }

        if ($isPartner && !TeamService::shareFits($accountId, (string) $request->share)) {
            return response()->json([
                'success' => false,
                'message' => 'Le total des parts dépasse 100 %',
                'errors' => ['share' => ['Le total des parts dépasse 100 %']],
            ], 422);
        }

        if ($hasAccess && TeamService::hasReachedMemberLimit($accountId)) {
            return response()->json([
                'success' => false,
                'message' => 'Le nombre maximal de membres (' . config('team.max_members') . ') est atteint pour ce compte.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $accountId, $isPartner, $hasAccess) {
            $accessUser = null;
            $code = null;

            if ($hasAccess) {
                [$accessUser, $code] = TeamService::createMemberAccount(
                    $accountId,
                    $request->name,
                    $request->email,
                    $request->phone,
                    $request->role
                );

                if ($request->filled('permissions_overrides')) {
                    TeamService::applyPermissionOverrides($accessUser, $request->permissions_overrides);
                }
            }

            $collaborator = null;
            if ($isPartner) {
                $owner = User::where('id', $accountId)->lockForUpdate()->first();

                $collaborator = Collaborator::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $accountId,
                    'user_id_member' => $accessUser?->id,
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'email' => $request->email,
                    'part' => $request->share,
                    'image' => $request->photo,
                ]);

                $owner->company_share = bcsub($owner->company_share, (string) $request->share, 2);
                $owner->save();

                NotificationService::notifyTeamJoined($collaborator);
            }

            if ($accessUser && $collaborator) {
                TeamService::assertAccountConsistency($accessUser, $collaborator);
            }

            ActivityLogger::log(Auth::id(), 'team.person_added', User::class, $accessUser?->id ?? $collaborator?->id, [
                'is_partner' => $isPartner,
                'has_access' => $hasAccess,
            ]);

            $data = [
                'team_member' => $this->personPayload($accountId, $accessUser, $collaborator),
            ];

            if ($hasAccess) {
                $data['credentials'] = [
                    'username' => $accessUser->username,
                    'code' => $code,
                    'login_url' => rtrim((string) config('app.frontend_url'), '/') . '/login',
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Personne ajoutée avec succès',
                'data' => $data,
            ], 201);
        });
    }

    /**
     * PATCH /api/team/{id} — nom/coordonnées (synchronisés sur le compte
     * d'accès ET la ligne associé quand les deux existent), part (règles
     * habituelles), rôle et permissions (révoque les sessions si l'un des
     * deux change réellement).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire ne se gère pas depuis cet endpoint'], 403);
        }

        $resolved = $this->resolvePerson($accountId, $id);
        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Personne non trouvée'], 404);
        }
        $accessUser = $resolved['user'];
        $collaborator = $resolved['collaborator'];

        // unique:users,email seulement si cette personne a un accès (son email vit
        // alors sur `users`, colonne unique globale) — un associé sans accès a son
        // email sur `collaborators.email`, sans contrainte d'unicité. ->ignore()
        // pour ne pas se rejeter lui-même quand l'email soumis n'a pas changé.
        $emailRules = ['sometimes', 'nullable', 'email', 'max:255'];
        if ($accessUser) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($accessUser->id);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'email' => $emailRules,
            'photo' => 'sometimes|nullable|string|max:255',
            'share' => 'sometimes|numeric|min:0.01|max:99.99',
            'role' => 'sometimes|string|in:' . implode(',', array_diff(Permissions::ROLES, ['owner'])),
            'permissions_overrides' => 'sometimes|array',
            'permissions_overrides.*.permission' => 'required_with:permissions_overrides|string|in:' . implode(',', Permissions::ALL),
            'permissions_overrides.*.granted' => 'required_with:permissions_overrides|boolean',
        ], [
            'email.unique' => 'Cet email est déjà utilisé par un autre compte.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $validator->errors()], 422);
        }

        if ($request->has('share') && !$collaborator) {
            return response()->json(['success' => false, 'message' => "Cette personne n'est pas associée"], 422);
        }
        if (($request->has('role') || $request->filled('permissions_overrides')) && !$accessUser) {
            return response()->json(['success' => false, 'message' => "Cette personne n'a pas d'accès"], 422);
        }

        return DB::transaction(function () use ($request, $accountId, $accessUser, $collaborator) {
            $this->syncContactInfo($request, $accessUser, $collaborator);

            if ($request->has('share')) {
                $owner = User::where('id', $accountId)->lockForUpdate()->first();

                if (!TeamService::shareFits($accountId, (string) $request->share, $collaborator->id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Le total des parts dépasse 100 %',
                        'errors' => ['share' => ['Le total des parts dépasse 100 %']],
                    ], 422);
                }

                $delta = bcsub((string) $collaborator->part, (string) $request->share, 2);
                $collaborator->part = $request->share;
                $collaborator->save();

                $owner->company_share = bcadd($owner->company_share, $delta, 2);
                $owner->save();
            }

            $accessChanged = false;
            if ($accessUser) {
                if ($request->has('role') && $request->role !== $accessUser->role) {
                    $accessUser->role = $request->role;
                    $accessUser->save();
                    $accessChanged = true;
                }
                if ($request->filled('permissions_overrides')) {
                    TeamService::applyPermissionOverrides($accessUser, $request->permissions_overrides);
                    $accessChanged = true;
                }
                if ($accessChanged) {
                    // Changement de rôle OU de permissions : les droits
                    // effectifs changent, une session déjà ouverte ne doit
                    // pas continuer à agir avec les anciens.
                    SessionRevoker::revoke($accessUser->id);
                }
            }

            ActivityLogger::log(Auth::id(), 'team.person_updated', User::class, $accessUser?->id ?? $collaborator?->id, [
                'access_changed' => $accessChanged,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Personne mise à jour avec succès',
                'data' => [
                    'team_member' => $this->personPayload($accountId, $accessUser?->fresh(), $collaborator?->fresh()),
                ],
            ]);
        });
    }

    /**
     * POST /api/team/{id}/access — donne un accès à un associé qui n'en
     * avait pas encore ({id} est alors forcément un id de collaborators,
     * puisqu'il n'a pas encore de compte).
     */
    public function grantAccess(Request $request, string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire ne se gère pas depuis cet endpoint'], 403);
        }

        $collaborator = Collaborator::where('id', $id)->where('user_id', $accountId)->first();
        if (!$collaborator) {
            return response()->json(['success' => false, 'message' => 'Associé non trouvé'], 404);
        }
        if ($collaborator->user_id_member) {
            return response()->json(['success' => false, 'message' => 'Cette personne a déjà un accès'], 422);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:' . implode(',', array_diff(Permissions::ROLES, ['owner'])),
            'permissions_overrides' => 'sometimes|array',
            'permissions_overrides.*.permission' => 'required_with:permissions_overrides|string|in:' . implode(',', Permissions::ALL),
            'permissions_overrides.*.granted' => 'required_with:permissions_overrides|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $validator->errors()], 422);
        }

        if (TeamService::hasReachedMemberLimit($accountId)) {
            return response()->json([
                'success' => false,
                'message' => 'Le nombre maximal de membres (' . config('team.max_members') . ') est atteint pour ce compte.',
            ], 422);
        }

        // Même garde que store()/update() : l'email de cet associé (déjà en base,
        // pas resaisi ici) va migrer sur `users.email` (unique globale) dès qu'il
        // obtient un accès — sans ce contrôle, une collision remonte en 500.
        if ($collaborator->email && User::where('email', $collaborator->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => ['email' => ["L'email {$collaborator->email} de cet associé est déjà utilisé par un autre compte"]],
            ], 422);
        }

        return DB::transaction(function () use ($request, $accountId, $collaborator) {
            [$accessUser, $code] = TeamService::createMemberAccount(
                $accountId,
                $collaborator->name,
                $collaborator->email,
                $collaborator->phone,
                $request->role
            );

            $collaborator->user_id_member = $accessUser->id;
            $collaborator->save();

            TeamService::assertAccountConsistency($accessUser, $collaborator);

            if ($request->filled('permissions_overrides')) {
                TeamService::applyPermissionOverrides($accessUser, $request->permissions_overrides);
            }

            ActivityLogger::log(Auth::id(), 'team.access_granted', User::class, $accessUser->id, [
                'collaborator_id' => $collaborator->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accès créé avec succès',
                'data' => [
                    'team_member' => $this->personPayload($accountId, $accessUser, $collaborator->fresh()),
                    'credentials' => [
                        'username' => $accessUser->username,
                        'code' => $code,
                        'login_url' => rtrim((string) config('app.frontend_url'), '/') . '/login',
                    ],
                ],
            ], 201);
        });
    }

    public function suspend(string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire ne peut pas être suspendu'], 403);
        }

        $accessUser = $this->resolveAccessUser($accountId, $id);
        if (!$accessUser) {
            return response()->json(['success' => false, 'message' => "Membre non trouvé ou sans accès"], 404);
        }

        $accessUser->update(['status' => 'suspended']);
        SessionRevoker::revoke($accessUser->id);

        ActivityLogger::log(Auth::id(), 'team.member_suspended', User::class, $accessUser->id);

        return response()->json(['success' => true, 'message' => 'Membre suspendu']);
    }

    public function reactivate(string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire est toujours actif'], 403);
        }

        $accessUser = $this->resolveAccessUser($accountId, $id);
        if (!$accessUser) {
            return response()->json(['success' => false, 'message' => "Membre non trouvé ou sans accès"], 404);
        }

        $accessUser->update(['status' => 'active']);

        ActivityLogger::log(Auth::id(), 'team.member_reactivated', User::class, $accessUser->id);

        return response()->json(['success' => true, 'message' => 'Membre réactivé']);
    }

    public function resetCode(string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire ne se gère pas depuis cet endpoint'], 403);
        }

        $accessUser = $this->resolveAccessUser($accountId, $id);
        if (!$accessUser) {
            return response()->json(['success' => false, 'message' => "Membre non trouvé ou sans accès"], 404);
        }

        $code = TeamService::generateCode();

        $accessUser->update([
            'password' => \Illuminate\Support\Facades\Hash::make($code),
            'must_change_password' => true,
        ]);

        SessionRevoker::revoke($accessUser->id);

        ActivityLogger::log(Auth::id(), 'team.member_code_reset', User::class, $accessUser->id);

        return response()->json([
            'success' => true,
            'message' => 'Code réinitialisé',
            'data' => ['login_code' => $code],
        ]);
    }

    /**
     * DELETE /api/team/{id} — retire l'accès (compte DÉSACTIVÉ, jamais
     * supprimé : garde l'historique des ventes et leur auteur) et, si la
     * personne est associée, retire sa part (mêmes règles que
     * CollaboratorController::destroy()).
     */
    public function destroy(string $id): JsonResponse
    {
        $accountId = CurrentAccount::id();

        if ($id === $accountId) {
            return response()->json(['success' => false, 'message' => 'Le propriétaire ne peut jamais être retiré'], 403);
        }

        $resolved = $this->resolvePerson($accountId, $id);
        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Personne non trouvée'], 404);
        }
        $accessUser = $resolved['user'];
        $collaborator = $resolved['collaborator'];

        return DB::transaction(function () use ($accountId, $accessUser, $collaborator) {
            if ($accessUser) {
                $accessUser->update(['status' => 'suspended']);
                SessionRevoker::revoke($accessUser->id);
            }

            if ($collaborator) {
                $owner = User::where('id', $accountId)->lockForUpdate()->first();
                $partToReturn = $collaborator->part;
                $collaborator->delete();
                $owner->company_share = bcadd($owner->company_share, $partToReturn, 2);
                $owner->save();
            }

            ActivityLogger::log(Auth::id(), 'team.person_removed', User::class, $accessUser?->id ?? $collaborator?->id);

            return response()->json(['success' => true, 'message' => "Retiré de l'équipe avec succès"]);
        });
    }

    /**
     * GET /api/team/roles — rôles disponibles (hors "owner", jamais
     * assignable), permissions par défaut et libellés fr/en — alimente
     * l'écran de choix du rôle.
     */
    public function roles(): JsonResponse
    {
        $roles = [];
        foreach (array_diff(Permissions::ROLES, ['owner']) as $role) {
            $roles[] = [
                'role' => $role,
                'label' => self::ROLE_LABELS[$role],
                'permissions' => Permissions::defaultsForRole($role),
            ];
        }

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * GET /api/team/activity?member=&page=&per_page=
     */
    public function activity(Request $request): JsonResponse
    {
        $accountId = CurrentAccount::id();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $query = ActivityLog::where('account_id', $accountId)->orderByDesc('created_at');

        if ($request->filled('member')) {
            $query->where('user_id', $request->input('member'));
        }

        $total = (clone $query)->count();
        $logs = $query->forPage($page, $perPage)->get();

        return response()->json([
            'success' => true,
            'data' => $logs,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ]);
    }

    // -------------------------------------------------------------------
    // Aides internes
    // -------------------------------------------------------------------

    /**
     * Résout {id} vers le compte d'accès et/ou la ligne associé qui lui
     * correspondent — {id} est soit un users.id (accès), soit un
     * collaborators.id (associé sans accès). Null si rien ne correspond
     * dans CE compte.
     */
    private function resolvePerson(string $accountId, string $id): ?array
    {
        $accessUser = User::where('id', $id)->where('account_id', $accountId)->first();
        if ($accessUser) {
            $collaborator = Collaborator::where('user_id_member', $accessUser->id)->where('user_id', $accountId)->first();

            return ['user' => $accessUser, 'collaborator' => $collaborator];
        }

        $collaborator = Collaborator::where('id', $id)->where('user_id', $accountId)->first();
        if ($collaborator) {
            $accessUser = $collaborator->user_id_member
                ? User::where('id', $collaborator->user_id_member)->where('account_id', $accountId)->first()
                : null;

            return ['user' => $accessUser, 'collaborator' => $collaborator];
        }

        return null;
    }

    private function resolveAccessUser(string $accountId, string $id): ?User
    {
        return $this->resolvePerson($accountId, $id)['user'] ?? null;
    }

    /**
     * Met à jour nom/téléphone/e-mail/photo sur CHAQUE enregistrement
     * existant (compte d'accès et/ou ligne associé) — les deux
     * représentent la MÊME personne, ils restent synchronisés.
     */
    private function syncContactInfo(Request $request, ?User $accessUser, ?Collaborator $collaborator): void
    {
        if (!$request->has('name') && !$request->has('phone') && !$request->has('email') && !$request->has('photo')) {
            return;
        }

        if ($accessUser) {
            if ($request->has('name')) {
                [$first, $last] = TeamService::splitName($request->name);
                $accessUser->first_name = $first;
                $accessUser->last_name = $last;
            }
            if ($request->has('phone')) {
                $accessUser->phone = $request->phone;
            }
            if ($request->has('email')) {
                $accessUser->email = $request->email;
            }
            if ($request->has('photo')) {
                $accessUser->profile_image = $request->photo;
            }
            $accessUser->save();
        }

        if ($collaborator) {
            if ($request->has('name')) {
                $collaborator->name = $request->name;
            }
            if ($request->has('phone')) {
                $collaborator->phone = $request->phone;
            }
            if ($request->has('email')) {
                $collaborator->email = $request->email;
            }
            if ($request->has('photo')) {
                $collaborator->image = $request->photo;
            }
            $collaborator->save();
        }
    }

    /**
     * Nombre de ventes + total encaissé sur les 30 derniers jours pour UN
     * membre donné — même convention que GET /api/team : seulement pour
     * qui a un accès (sinon null, voir personPayload()).
     */
    private function salesStats(string $accountId, string $memberId): array
    {
        $query = fn () => Operation::where('user_id', $accountId)
            ->where('created_by_user_id', $memberId)
            ->where('type', 'sale')
            ->where('occurred_at', '>=', now()->subDays(30));

        return [
            'sales_count' => $query()->count(),
            'total' => round((float) $query()->sum('total_amount'), 2),
        ];
    }

    /**
     * Construit la représentation UNIFIÉE d'une personne de l'équipe —
     * voir la doc de classe pour le choix de l'id canonique.
     */
    private function personPayload(string $accountId, ?User $accessUser, ?Collaborator $collaborator, bool $isOwner = false): array
    {
        if ($isOwner) {
            $owner = $accessUser;

            return [
                'id' => $owner->id,
                'name' => trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')),
                'photo' => $owner->profile_image,
                'phone' => $owner->phone,
                'email' => $owner->email,
                'is_owner' => true,
                'is_partner' => false,
                'part' => null,
                'part_amount' => null,
                'access' => 'active',
                'role' => null,
                'username' => $owner->username,
                'permissions' => Permissions::ALL,
                'last_login_at' => $owner->last_login_at,
                'last_30_days' => $this->salesStats($accountId, $owner->id),
            ];
        }

        $name = $collaborator?->name
            ?? trim(($accessUser->first_name ?? '') . ' ' . ($accessUser->last_name ?? ''));

        $access = 'none';
        if ($accessUser) {
            $access = $accessUser->status === 'suspended' ? 'suspended' : 'active';
        }

        return [
            'id' => $accessUser?->id ?? $collaborator?->id,
            'name' => $name,
            'photo' => $collaborator?->image ?? $accessUser?->profile_image,
            'phone' => $collaborator?->phone ?? $accessUser?->phone,
            'email' => $collaborator?->email ?? $accessUser?->email,
            'is_owner' => false,
            'is_partner' => $collaborator !== null,
            'part' => $collaborator ? (float) $collaborator->part : null,
            'part_amount' => $collaborator ? (float) $collaborator->wallet : null,
            'access' => $access,
            'role' => $accessUser?->role,
            'username' => $accessUser?->username,
            'permissions' => $accessUser ? Permissions::effectivePermissions($accessUser) : [],
            'last_login_at' => $accessUser?->last_login_at,
            'last_30_days' => $accessUser ? $this->salesStats($accountId, $accessUser->id) : null,
        ];
    }
}
