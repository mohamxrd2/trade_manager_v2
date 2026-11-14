<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Chemins exposés au CORS
    |--------------------------------------------------------------------------
    | Liste des chemins qui acceptent les requêtes cross-origin.
    | 'api/*' couvre toutes les routes API.
    | 'sanctum/csrf-cookie' est nécessaire pour l'authentification Sanctum SPA.
    */
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Méthodes HTTP autorisées
    |--------------------------------------------------------------------------
    | Liste explicite des méthodes HTTP acceptées.
    */
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Origines autorisées
    |--------------------------------------------------------------------------
    | Aucun domaine codé en dur : FRONTEND_URL pilote l'origine principale
    | (http://localhost:3000 en local, https://votre-domaine.com en prod — définis
    | uniquement dans .env local ou les variables d'environnement Render).
    | ADDITIONAL_CORS_ORIGINS (optionnel, liste séparée par des virgules)
    | permet d'autoriser des origines supplémentaires sans toucher au code
    | (ex: variante "www.").
    | ⚠️ En production, NE JAMAIS utiliser '*' avec supports_credentials=true
    */
    // Liste unique, voir App\Support\FrontendOrigins (CORS_ALLOWED_ORIGINS,
    // variante www. ajoutée d'office, localhost en local seulement).
    'allowed_origins' => \App\Support\FrontendOrigins::origins(),

    /*
    |--------------------------------------------------------------------------
    | Patterns d'origines autorisées (regex)
    |--------------------------------------------------------------------------
    | Permet d'autoriser des domaines via des patterns regex.
    | Utile pour les environnements de preview (Vercel, Netlify, etc.)
    */
    // Previews Vercel de CE projet seulement (CORS_VERCEL_PROJECT +
    // CORS_VERCEL_SCOPE) — jamais tout *.vercel.app : n'importe qui peut y
    // héberger un site, et les cookies de session sont autorisés.
    'allowed_origins_patterns' => \App\Support\FrontendOrigins::patterns(),

    /*
    |--------------------------------------------------------------------------
    | Headers autorisés dans les requêtes
    |--------------------------------------------------------------------------
    | Liste explicite des headers que le client peut envoyer.
    */
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'Origin',
        'Cache-Control',
        'Pragma',
        // Clé d'idempotence envoyée par le frontend sur POST /api/transactions
        // (voir TransactionController::store()) — absente d'ici, le préflight
        // CORS la refuse et le navigateur bloque la requête réelle avant même
        // qu'elle n'atteigne Laravel (vu côté client comme "Network Error").
        'Idempotency-Key',
        // Compte affiché par l'onglet, envoyé sur toute écriture (voir
        // EnsureAccountHeaderMatchesSession) — même raison : sans lui ici, le
        // préflight échoue et TOUTES les écritures du navigateur sont bloquées.
        'X-Account-Id',
        // Langue de l'appli (messages d'erreur traduits).
        'Accept-Language',
        // Identifiant de requête envoyé par le front (retrouver une requête
        // dans les logs à partir du message d'erreur).
        'X-Request-Id',
        // Toute nouvelle entrée doit aussi figurer dans le front
        // (lib/api-headers.ts) : un test compare les deux listes.
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers exposés dans les réponses
    |--------------------------------------------------------------------------
    | Headers que le navigateur peut lire dans les réponses.
    */
    'exposed_headers' => [
        'Authorization',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Retry-After',
        // Compte de la session, comparé par le front au compte affiché par
        // l'onglet (voir EnsureAccountHeaderMatchesSession).
        'X-Account-Id',
        // Identifiant de la requête (affiché dans les messages d'erreur).
        'X-Request-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Durée de cache preflight (en secondes)
    |--------------------------------------------------------------------------
    | Durée pendant laquelle le navigateur peut mettre en cache
    | la réponse preflight OPTIONS. 86400 = 24 heures.
    */
    'max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Support des credentials (cookies, authorization headers)
    |--------------------------------------------------------------------------
    | ⚠️ IMPORTANT: Si true, 'allowed_origins' NE PEUT PAS contenir '*'
    | Nécessaire pour l'authentification avec cookies (Sanctum SPA).
    */
    'supports_credentials' => true,

];

