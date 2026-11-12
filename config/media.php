<?php

/*
| Images envoyées par les comptes (voir App\Services\MediaService).
*/
return [
    // Taille maximale d'un fichier reçu, en kilo-octets.
    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 5120),

    // Limites par compte, contre les abus.
    'max_per_account' => (int) env('MEDIA_MAX_PER_ACCOUNT', 2000),
    'max_bytes_per_account' => (int) env('MEDIA_MAX_BYTES_PER_ACCOUNT', 500 * 1024 * 1024),

    // Côté le plus long, en pixels, après ré-encodage.
    'max_dimensions' => [
        'product' => 1200,
        'variation' => 1200,
        'logo' => 512,
        'avatar' => 512,
    ],

    // Qualité WebP (0 à 100).
    'webp_quality' => (int) env('MEDIA_WEBP_QUALITY', 80),

    // Formats acceptés, d'après le contenu réel du fichier (finfo).
    'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Un envoi jamais rattaché (article abandonné…) est supprimé après ce délai.
    'orphan_ttl_hours' => 24,
];
