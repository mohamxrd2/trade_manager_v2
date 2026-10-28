<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Chantier "Équipe" — révocation immédiate des sessions d'un membre
 * (suspension, changement de rôle, réinitialisation du code). L'app
 * utilise l'authentification par session Sanctum/SPA (guard "web",
 * SESSION_DRIVER=database) — PAS de tokens Sanctum (`createToken()` n'est
 * utilisé nulle part) — donc "révoquer toutes les sessions" se traduit
 * simplement par supprimer les lignes de la table `sessions` pour cet
 * utilisateur : la prochaine requête de ce membre, quel que soit son
 * navigateur, échouera l'authentification.
 */
class SessionRevoker
{
    public static function revoke(string $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
