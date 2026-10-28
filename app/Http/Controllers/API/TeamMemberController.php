<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MemberPermission;
use App\Models\User;
use App\Support\AccountCode;
use App\Support\ActivityLogger;
use App\Support\CurrentAccount;
use App\Support\Permissions;
use App\Support\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Chantier "Équipe" — gestion des MEMBRES (accès applicatif) : création,
 * rôle/permissions, suspension, réinitialisation du code. Distinct de
 * CollaboratorController (ASSOCIÉS, les parts de l'entreprise) — un membre
 * n'est pas un associé, et inversement (voir App\Support\Permissions).
 *
 * Toutes ces actions sont gated par team.manage, jamais par isOwner() —
 * contrairement à la gestion des parts, team.manage EST une permission
 * normale, accordable à un manager via member_permissions si le
 * propriétaire le souhaite explicitement (elle est seulement absente des
 * valeurs par défaut du rôle manager).
 */
class TeamMemberController extends Controller
{
    public function index(): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $members = User::where('account_id', CurrentAccount::id())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'first_name', 'last_name', 'username', 'email', 'phone', 'role', 'status', 'must_change_password', 'last_login_at', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'role' => 'required|string|in:' . implode(',', array_diff(Permissions::ROLES, ['owner'])),
            'email' => 'nullable|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'permissions' => 'sometimes|array',
            'permissions.*.permission' => 'required_with:permissions|string|in:' . implode(',', Permissions::ALL),
            'permissions.*.granted' => 'required_with:permissions|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $accountId = CurrentAccount::id();
        $company = Company::where('user_id', $accountId)->first();

        // Dérive et fige le code entreprise si ce n'est pas déjà fait
        // (comptes créés avant ce chantier → voir TeamBackfill ; un compte
        // créé APRÈS ce chantier mais pas encore passé par le backfill
        // obtient son code ici, à la volée, au premier membre créé).
        if ($company && !$company->code) {
            $base = AccountCode::slug($company->name) ?: 'ENTREPRISE';
            $company->code = AccountCode::withUniqueSuffix(
                $base,
                fn (string $candidate) => Company::where('code', $candidate)->exists()
            );
            $company->save();
        }

        $companyCode = $company?->code ?: 'ENTREPRISE';
        $firstNameSlug = AccountCode::slug($request->first_name) ?: 'MEMBRE';
        $usernameBase = $companyCode . '-' . $firstNameSlug;
        $username = AccountCode::withUniqueSuffix(
            $usernameBase,
            fn (string $candidate) => User::where('username', $candidate)->exists()
        );

        // Code à 6 chiffres — jamais stocké en clair, renvoyé UNE SEULE FOIS
        // dans cette réponse (voir plus bas) : au propriétaire de le
        // transmettre au membre, qui doit le changer à sa première connexion
        // (must_change_password).
        $code = (string) random_int(100000, 999999);

        $member = User::create([
            'account_id' => $accountId,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name ?? '',
            'username' => $username,
            'email' => $request->email ?: null,
            'phone' => $request->phone,
            'password' => Hash::make($code),
            'role' => $request->role,
            'must_change_password' => true,
            'status' => 'active',
            'company_share' => 0,
        ]);

        // Un membre n'a pas d'email à vérifier (voir spec "aucune
        // vérification d'email pour les membres") — email_verified_at posé
        // directement pour qu'AuthController::login() ne le bloque jamais
        // sur ce point, indépendamment de la garde explicite isMember().
        $member->forceFill(['email_verified_at' => now()])->save();

        foreach ($request->input('permissions', []) as $adjustment) {
            MemberPermission::updateOrCreate(
                ['user_id' => $member->id, 'permission' => $adjustment['permission']],
                ['granted' => $adjustment['granted']]
            );
        }

        ActivityLogger::log(Auth::id(), 'team.member_added', User::class, $member->id, [
            'role' => $member->role,
            'username' => $member->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Membre créé avec succès',
            'data' => [
                'member' => $member->fresh(),
                'login_code' => $code,
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $member = User::where('id', $id)->where('account_id', CurrentAccount::id())->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Membre non trouvé'], 404);
        }

        $validator = Validator::make($request->all(), [
            'role' => 'sometimes|string|in:' . implode(',', array_diff(Permissions::ROLES, ['owner'])),
            'permissions' => 'sometimes|array',
            'permissions.*.permission' => 'required_with:permissions|string|in:' . implode(',', Permissions::ALL),
            'permissions.*.granted' => 'required_with:permissions|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $roleChanged = $request->has('role') && $request->role !== $member->role;

        if ($roleChanged) {
            $member->role = $request->role;
            $member->save();
        }

        foreach ($request->input('permissions', []) as $adjustment) {
            MemberPermission::updateOrCreate(
                ['user_id' => $member->id, 'permission' => $adjustment['permission']],
                ['granted' => $adjustment['granted']]
            );
        }

        // Changement de rôle = révocation immédiate (les permissions
        // effectives changent, une session déjà ouverte ne doit pas
        // continuer à agir avec les anciens droits).
        if ($roleChanged) {
            SessionRevoker::revoke($member->id);
        }

        ActivityLogger::log(Auth::id(), 'team.member_updated', User::class, $member->id, [
            'role_changed' => $roleChanged,
            'permissions' => $request->input('permissions', []),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Membre mis à jour avec succès',
            'data' => $member->fresh(),
        ]);
    }

    public function suspend(string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $member = User::where('id', $id)->where('account_id', CurrentAccount::id())->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Membre non trouvé'], 404);
        }

        $member->update(['status' => 'suspended']);
        SessionRevoker::revoke($member->id);

        ActivityLogger::log(Auth::id(), 'team.member_suspended', User::class, $member->id);

        return response()->json(['success' => true, 'message' => 'Membre suspendu']);
    }

    public function reactivate(string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $member = User::where('id', $id)->where('account_id', CurrentAccount::id())->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Membre non trouvé'], 404);
        }

        $member->update(['status' => 'active']);

        ActivityLogger::log(Auth::id(), 'team.member_reactivated', User::class, $member->id);

        return response()->json(['success' => true, 'message' => 'Membre réactivé']);
    }

    public function resetCode(string $id): JsonResponse
    {
        if (Auth::user()->cannot(Permissions::TEAM_MANAGE)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $member = User::where('id', $id)->where('account_id', CurrentAccount::id())->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Membre non trouvé'], 404);
        }

        $code = (string) random_int(100000, 999999);

        $member->update([
            'password' => Hash::make($code),
            'must_change_password' => true,
        ]);

        SessionRevoker::revoke($member->id);

        ActivityLogger::log(Auth::id(), 'team.member_code_reset', User::class, $member->id);

        return response()->json([
            'success' => true,
            'message' => 'Code réinitialisé',
            'data' => ['login_code' => $code],
        ]);
    }
}
