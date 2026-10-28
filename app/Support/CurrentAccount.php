<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Point d'accès UNIQUE au compte courant — remplace `Auth::id()` dans
 * toutes les requêtes métier scopées par compte (articles, opérations,
 * transactions, factures, clients, associés, paramètres...). Pour un
 * propriétaire, `id()` renvoie son propre id (rien ne change). Pour un
 * membre, `id()` renvoie l'id du propriétaire auquel il est rattaché — les
 * données qu'il crée/consulte sont donc TOUJOURS celles du compte, jamais
 * les siennes propres.
 *
 * `actorId()` reste `Auth::id()` tel quel — l'AUTEUR réel de l'action
 * (propriétaire ou membre), utilisé pour `created_by_user_id` et le journal
 * d'activité. Ne jamais confondre les deux : `id()` = à QUI appartiennent
 * les données, `actorId()` = QUI a fait l'action.
 */
class CurrentAccount
{
    public static function id(): ?string
    {
        $user = Auth::user();

        return $user?->accountId();
    }

    public static function actorId(): ?string
    {
        return Auth::id();
    }
}
