<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Permissions;

/**
 * Chantier "Équipe" — même convention que OperationPolicy : ces méthodes
 * s'exécutent pour TOUT utilisateur, propriétaire compris (voir le
 * commentaire détaillé dans OperationPolicy et
 * AppServiceProvider::registerPermissionGate() pour la raison : un bypass
 * inconditionnel casserait l'isolation entre comptes).
 */
class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        if ($user->accountId() !== $transaction->user_id) {
            return false;
        }

        if (Permissions::userHas($user, Permissions::SALES_VIEW_ALL)) {
            return true;
        }

        return Permissions::userHas($user, Permissions::SALES_VIEW_OWN)
            && $transaction->operation?->created_by_user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        if ($user->accountId() !== $transaction->user_id) {
            return false;
        }

        if (Permissions::userHas($user, Permissions::SALES_DELETE_ANY)) {
            return true;
        }

        if (!Permissions::userHas($user, Permissions::SALES_DELETE_OWN_RECENT)) {
            return false;
        }

        $createdBy = $transaction->operation?->created_by_user_id;

        return $createdBy === $user->id && $transaction->created_at?->gt(now()->subMinutes(10));
    }

    public function restore(User $user, Transaction $transaction): bool
    {
        return $this->delete($user, $transaction);
    }

    public function forceDelete(User $user, Transaction $transaction): bool
    {
        return false;
    }
}
