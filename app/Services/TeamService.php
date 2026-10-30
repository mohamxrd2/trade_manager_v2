<?php

namespace App\Services;

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\MemberPermission;
use App\Models\User;
use App\Support\AccountCode;
use App\Support\SessionRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Chantier "Équipe" étape 2 — logique PARTAGÉE entre CollaboratorController
 * (ancien endpoint /api/collaborators, conservé pour compatibilité) et
 * TeamController (nouveau endpoint unifié /api/team) : validation de la
 * somme des parts, génération des identifiants de connexion d'un membre,
 * quota de membres. Aucun des deux contrôleurs ne doit réimplémenter ces
 * règles indépendamment — c'est tout le sens de ce service.
 */
class TeamService
{
    /**
     * Vrai si la somme des parts des AUTRES associés (celui qu'on modifie,
     * le cas échéant, est exclu via $excludeCollaboratorId) plus $newPart
     * ne dépasse pas 100 % — jamais sur `company_share`, qui peut être
     * décalé (voir `variations:resync`).
     */
    public static function shareFits(string $accountId, string $newPart, ?string $excludeCollaboratorId = null): bool
    {
        $query = Collaborator::where('user_id', $accountId);
        if ($excludeCollaboratorId) {
            $query->where('id', '!=', $excludeCollaboratorId);
        }
        $sumOthers = (string) $query->sum('part');
        $projected = bcadd($sumOthers, (string) $newPart, 2);

        return bccomp($projected, '100', 2) <= 0;
    }

    /**
     * Dérive et fige le code entreprise si absent, puis génère
     * {CODE-ENTREPRISE}-{PRENOM} (majuscules, sans accents), avec un
     * suffixe numérique en cas de doublon (unicité globale — voir
     * users.username).
     */
    public static function generateUsername(string $accountId, string $firstName): string
    {
        $company = Company::where('user_id', $accountId)->first();

        if ($company && !$company->code) {
            $base = AccountCode::slug($company->name) ?: 'ENTREPRISE';
            $company->code = AccountCode::withUniqueSuffix(
                $base,
                fn (string $candidate) => Company::where('code', $candidate)->exists()
            );
            $company->save();
        }

        $companyCode = $company?->code ?: 'ENTREPRISE';
        $firstNameSlug = AccountCode::slug($firstName) ?: 'MEMBRE';
        $usernameBase = $companyCode . '-' . $firstNameSlug;

        return AccountCode::withUniqueSuffix(
            $usernameBase,
            fn (string $candidate) => User::where('username', $candidate)->exists()
        );
    }

    /**
     * Code de connexion à 6 chiffres — jamais stocké en clair (toujours
     * haché via Hash::make() avant persistance), renvoyé UNE SEULE FOIS à
     * l'appelant immédiatement après génération.
     */
    public static function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Nombre de comptes à ACCÈS (users.account_id défini) sur ce compte —
     * base du quota config('team.max_members'). Un associé SANS accès ne
     * compte pas dans ce total.
     */
    public static function memberCount(string $accountId): int
    {
        return User::where('account_id', $accountId)->count();
    }

    public static function hasReachedMemberLimit(string $accountId): bool
    {
        return self::memberCount($accountId) >= (int) config('team.max_members');
    }

    /**
     * "Koffi Yao" -> ["Koffi", "Yao"] ; "Koffi" -> ["Koffi", ""] — même
     * heuristique que SocialAuthController::createUserFromSocialData(),
     * seul précédent existant dans ce code pour scinder un nom complet.
     */
    public static function splitName(string $name): array
    {
        $parts = explode(' ', trim($name), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Crée le compte d'accès d'une personne (nouvelle recrue, ou octroi
     * d'accès à un associé déjà existant) — identifiant et code générés.
     * Retourne [User $member, string $plainCode] : le code en clair n'est
     * JAMAIS ré-affichable après cet appel, à l'appelant de le transmettre
     * dans sa réponse HTTP immédiate.
     */
    public static function createMemberAccount(string $accountId, string $name, ?string $email, ?string $phone, string $role): array
    {
        [$firstName, $lastName] = self::splitName($name);
        $username = self::generateUsername($accountId, $firstName);
        $code = self::generateCode();

        $member = User::create([
            'account_id' => $accountId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email ?: null,
            'phone' => $phone,
            'password' => Hash::make($code),
            'role' => $role,
            'must_change_password' => true,
            'status' => 'active',
            'company_share' => 0,
        ]);

        // Un membre n'a pas d'email à vérifier (voir chantier "Équipe"
        // étape 1) — posé directement pour qu'AuthController::login() ne
        // le bloque jamais sur ce point.
        $member->forceFill(['email_verified_at' => now()])->save();

        return [$member, $code];
    }

    /**
     * Garde-fou "sécurité" (chantier équipe, bug account_id mal rattaché) :
     * quand une personne a À LA FOIS un compte d'accès et une fiche associé,
     * les deux DOIVENT pointer sur le même compte — ils viennent toujours du
     * même $accountId dans la même transaction (voir store()/grantAccess()
     * dans TeamController), donc une divergence ici ne peut venir que d'une
     * corruption de données pré-existante. En local/testing on fait
     * échouer bruyamment (pour ne jamais laisser passer une régression en
     * silence) ; en production on journalise sans bloquer la requête
     * (mieux vaut un member fonctionnel et audité qu'une panne totale).
     */
    public static function assertAccountConsistency(User $member, Collaborator $collaborator): void
    {
        if ($member->account_id === $collaborator->user_id) {
            return;
        }

        $message = "Incohérence account_id : membre={$member->id} account_id={$member->account_id} "
            . "vs collaborator={$collaborator->id} user_id={$collaborator->user_id}";

        if (app()->environment(['local', 'testing'])) {
            throw new \RuntimeException($message);
        }

        Log::error($message, [
            'member_id' => $member->id,
            'member_account_id' => $member->account_id,
            'collaborator_id' => $collaborator->id,
            'collaborator_user_id' => $collaborator->user_id,
        ]);
    }

    public static function applyPermissionOverrides(User $member, array $overrides): void
    {
        foreach ($overrides as $adjustment) {
            MemberPermission::updateOrCreate(
                ['user_id' => $member->id, 'permission' => $adjustment['permission']],
                ['granted' => $adjustment['granted']]
            );
        }
    }

    /**
     * Nom affiché d'un utilisateur — « Prénom Nom », sinon son identifiant.
     */
    public static function displayName(User $user): ?string
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->username ?? null);
    }

    /**
     * Suppression DÉFINITIVE du compte d'un membre (jamais du propriétaire —
     * vérifié par l'appelant). Ce qu'il a fait reste dans l'historique : son
     * nom (et son rôle) est figé dans ses opérations, factures,
     * réapprovisionnements et entrées du journal AVANT la suppression — les
     * clés created_by_user_id / activity_logs.user_id passent ensuite à NULL
     * (nullOnDelete). Ses sessions, ses ajustements de permissions et son
     * identifiant disparaissent : l'identifiant redevient disponible.
     * À appeler dans une transaction.
     */
    public static function deleteMemberPermanently(User $member): void
    {
        $name = self::displayName($member);

        // created_by_user_id / user_id mis à NULL ici explicitement (et pas
        // seulement par les clés nullOnDelete) : le nom figé et la référence
        // effacée restent cohérents quel que soit le moteur de base.
        DB::table('operations')->where('created_by_user_id', $member->id)
            ->update(['created_by_name' => $name, 'created_by_role' => $member->role, 'created_by_user_id' => null]);
        DB::table('invoices')->where('created_by_user_id', $member->id)
            ->update(['created_by_name' => $name, 'created_by_user_id' => null]);
        DB::table('stock_replenishments')->where('created_by_user_id', $member->id)
            ->update(['created_by_name' => $name, 'created_by_user_id' => null]);
        DB::table('activity_logs')->where('user_id', $member->id)
            ->update(['user_name' => $name, 'user_id' => null]);

        SessionRevoker::revoke($member->id);
        $member->tokens()->delete();
        MemberPermission::where('user_id', $member->id)->delete();

        $member->delete();
    }
}
