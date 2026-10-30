<?php

/*
| Le frontend envoie X-Account-Id sur toute écriture (synchro de session
| entre onglets). Le front et l'API étant sur des origines différentes, le
| navigateur fait un préflight CORS : si l'en-tête n'y est pas autorisé,
| TOUTES les écritures sont bloquées avant d'atteindre Laravel.
*/

test('le préflight CORS autorise X-Account-Id et Idempotency-Key', function () {
    $origin = collect(config('cors.allowed_origins'))->first();

    $response = $this->call('OPTIONS', '/api/team', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-account-id,idempotency-key,content-type',
    ]);

    $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
    expect($allowed)->toContain('x-account-id')->toContain('idempotency-key');
});
