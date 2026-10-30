<?php

namespace App\Http\Middleware;

use App\Support\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronisation de session entre onglets (chantier équipe, bug account_id
 * mal rattaché) : le frontend pose `X-Account-Id` = le compte AFFICHÉ par
 * l'onglet sur chaque requête qui crée/modifie des données. Si un autre
 * onglet du même navigateur a changé de session (déconnexion + connexion à
 * un autre compte — ils partagent le même cookie Sanctum), un onglet resté
 * ouvert sur l'ancien compte envoie encore l'ancien X-Account-Id alors que
 * le cookie, lui, authentifie déjà le NOUVEAU compte : sans ce contrôle,
 * l'action s'exécuterait silencieusement sur le nouveau compte sans que cet
 * onglet s'en rende compte.
 *
 * Le compte réellement utilisé reste TOUJOURS celui de la session
 * (CurrentAccount::id(), voir TeamController/OperationController/etc.) —
 * ce middleware ne fait que REFUSER la requête avant qu'elle n'atteigne le
 * contrôleur quand les deux divergent, jamais consommer l'en-tête comme
 * source de vérité.
 *
 * Lecture seule (GET/HEAD/OPTIONS) et en-tête absent : laissé passer sans
 * contrôle — seules les requêtes qui écrivent et qui PRÉCISENT un compte
 * affiché sont concernées.
 */
class EnsureAccountHeaderMatchesSession
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $headerAccountId = $request->header('X-Account-Id');
        if (!$headerAccountId) {
            return $next($request);
        }

        if ($headerAccountId !== CurrentAccount::id()) {
            return response()->json([
                'success' => false,
                'code' => 'ACCOUNT_MISMATCH',
                'message' => 'Votre session a changé, rechargez la page.',
            ], 409);
        }

        return $next($request);
    }
}
