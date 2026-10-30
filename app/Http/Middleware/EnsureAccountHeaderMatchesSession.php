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
 * affiché sont refusées. En revanche TOUTE réponse renvoie `X-Account-Id`
 * (le compte de la session), que le frontend vérifie aussi sur les lectures.
 */
class EnsureAccountHeaderMatchesSession
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $accountId = CurrentAccount::id();
        $headerAccountId = $request->header('X-Account-Id');

        if (
            !in_array($request->method(), self::SAFE_METHODS, true)
            && $headerAccountId
            && $headerAccountId !== $accountId
        ) {
            return $this->withAccountHeader(response()->json([
                'success' => false,
                'code' => 'ACCOUNT_MISMATCH',
                'message' => 'Votre session a changé, rechargez la page.',
            ], 409), $accountId);
        }

        return $this->withAccountHeader($next($request), $accountId);
    }

    /**
     * Toute réponse porte le compte de la session : le frontend la compare au
     * compte AFFICHÉ par l'onglet et recharge s'ils divergent — couvre aussi
     * les LECTURES (sinon un onglet resté sur le compte A afficherait, sous
     * l'étiquette A, des données du compte B).
     */
    private function withAccountHeader(Response $response, ?string $accountId): Response
    {
        if ($accountId) {
            $response->headers->set('X-Account-Id', $accountId);
        }

        return $response;
    }
}
