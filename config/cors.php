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
    | Liste des domaines autorisés à faire des requêtes cross-origin.
    | ⚠️ En production, NE JAMAIS utiliser '*' avec supports_credentials=true
    */
    'allowed_origins' => array_filter([
        'http://localhost:3000',                    // Développement local Next.js
        'http://127.0.0.1:3000',                    // Alternative localhost
        env('FRONTEND_URL'),                        // URL frontend depuis .env
        'https://mon-domaine-front.com',            // Production
        'https://www.mon-domaine-front.com',        // Production avec www
    ]),

    /*
    |--------------------------------------------------------------------------
    | Patterns d'origines autorisées (regex)
    |--------------------------------------------------------------------------
    | Permet d'autoriser des domaines via des patterns regex.
    | Utile pour les environnements de preview (Vercel, Netlify, etc.)
    */
    'allowed_origins_patterns' => [
        // Vercel preview deployments
        '#^https://.*\.vercel\.app$#',
        // Netlify preview deployments  
        '#^https://.*\.netlify\.app$#',
    ],

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

