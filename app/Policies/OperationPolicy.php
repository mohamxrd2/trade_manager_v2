<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;

/**
 * Même convention que TransactionPolicy — seul le propriétaire du compte
 * peut supprimer/restaurer une opération (les rôles d'équipe viendront
 * plus tard s'ajouter ici).
 */
class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Operation $operation): bool
    {
        return $user->id === $operation->user_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Operation $operation): bool
    {
        return false;
    }

    public function delete(User $user, Operation $operation): bool
    {
        return $user->id === $operation->user_id;
    }

    public function restore(User $user, Operation $operation): bool
    {
        return $user->id === $operation->user_id;
    }

    public function forceDelete(User $user, Operation $operation): bool
    {
        return false;
    }
}
