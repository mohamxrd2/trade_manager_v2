<?php

namespace App\Services;

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\MemberPermission;
use App\Models\User;
use App\Support\AccountCode;
use Illuminate\Support\Facades\Hash;

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

    public static function applyPermissionOverrides(User $member, array $overrides): void
    {
        foreach ($overrides as $adjustment) {
            MemberPermission::updateOrCreate(
                ['user_id' => $member->id, 'permission' => $adjustment['permission']],
                ['granted' => $adjustment['granted']]
            );
        }
    }
}
