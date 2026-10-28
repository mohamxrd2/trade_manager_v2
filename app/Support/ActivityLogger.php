<?php

namespace App\Support;

use App\Models\ActivityLog;

/**
 * Point d'écriture UNIQUE du journal d'activité (voir activity_logs) — évite
 * que chaque contrôleur ne reconstruise le même tableau de colonnes.
 * `account_id` vient toujours de CurrentAccount::id() (jamais de l'acteur
 * directement) pour que le journal d'un compte reste lisible en une seule
 * requête, quel que soit qui a agi dessus (propriétaire ou membre).
 */
class ActivityLogger
{
    public static function log(string $actorUserId, string $action, ?string $subjectType = null, ?string $subjectId = null, array $details = []): void
    {
        ActivityLog::create([
            'account_id' => CurrentAccount::id(),
            'user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => $details,
        ]);
    }
}
