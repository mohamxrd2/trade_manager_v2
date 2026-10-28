<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;
use App\Support\Permissions;

/**
 * Chantier "Équipe" — ces méthodes s'exécutent pour TOUT utilisateur,
 * propriétaire compris (Gate::before ne court-circuite que les chaînes de
 * permission connues, jamais les abilities de Policy — voir
 * AppServiceProvider::registerPermissionGate() ; un bypass inconditionnel
 * pour le propriétaire laisserait passer un propriétaire d'un AUTRE compte).
 * Toujours vérifier `accountId()` en premier : personne d'un autre compte ne
 * doit JAMAIS passer. `Permissions::userHas()` traite déjà le propriétaire
 * comme ayant toujours la permission demandée, donc aucune branche "if
 * owner" séparée n'est nécessaire ici.
 */
class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Operation $operation): bool
    {
        if ($user->accountId() !== $operation->user_id) {
            return false;
        }

        if (Permissions::userHas($user, Permissions::SALES_VIEW_ALL)) {
            return true;
        }

        return Permissions::userHas($user, Permissions::SALES_VIEW_OWN)
            && $operation->created_by_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Operation $operation): bool
    {
        return false;
    }

    /**
     * sales.delete_any : n'importe quelle vente du compte. sales.
     * delete_own_recent : seulement SES PROPRES ventes, et seulement dans
     * les 10 minutes suivant la création (jamais plus tard, même si c'est
     * bien sa propre vente).
     */
    public function delete(User $user, Operation $operation): bool
    {
        if ($user->accountId() !== $operation->user_id) {
            return false;
        }

        if (Permissions::userHas($user, Permissions::SALES_DELETE_ANY)) {
            return true;
        }

        if (!Permissions::userHas($user, Permissions::SALES_DELETE_OWN_RECENT)) {
            return false;
        }

        return $operation->created_by_user_id === $user->id
            && $operation->created_at?->gt(now()->subMinutes(10));
    }

    public function restore(User $user, Operation $operation): bool
    {
        return $this->delete($user, $operation);
    }

    public function forceDelete(User $user, Operation $operation): bool
    {
        return false;
    }
}
