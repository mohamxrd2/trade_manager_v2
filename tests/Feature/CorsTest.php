<?php

use App\Support\FrontendOrigins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| CORS : origines autorisées (App\Support\FrontendOrigins), en-têtes du
| front, erreurs avec en-têtes CORS, identifiant de requête, /api/health.
*/

/** En-têtes envoyés par le front — même liste que lib/api-headers.ts (le front compare les deux). */
const FRONT_REQUEST_HEADERS = [
    'Accept',
    'Accept-Language',
    'Content-Type',
    'X-Requested-With',
    'X-XSRF-TOKEN',
    'X-Account-Id',
    'Idempotency-Key',
    'X-Request-Id',
];

const CORS_ENV_KEYS = ['APP_ENV', 'CORS_ALLOWED_ORIGINS', 'FRONTEND_URL', 'ADDITIONAL_CORS_ORIGINS', 'CORS_VERCEL_PROJECT', 'CORS_VERCEL_SCOPE', 'SANCTUM_STATEFUL_DOMAINS'];

/** Variables d'environnement le temps d'un test, puis config CORS/Sanctum recalculée. */
function corsEnv(array $values): void
{
    foreach (CORS_ENV_KEYS as $key) {
        $value = array_key_exists($key, $values) ? $values[$key] : ($key === 'APP_ENV' ? 'testing' : null);
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }

    config([
        'cors.allowed_origins' => FrontendOrigins::origins(),
        'cors.allowed_origins_patterns' => FrontendOrigins::patterns(),
        'sanctum.stateful' => FrontendOrigins::statefulHosts(),
    ]);
}

function productionCors(): void
{
    corsEnv([
        'APP_ENV' => 'production',
        'CORS_ALLOWED_ORIGINS' => 'https://www.waelly.com, http://localhost:3000',
        'CORS_VERCEL_PROJECT' => 'front-trade-manager',
        'CORS_VERCEL_SCOPE' => 'mohamxrd2s-projects',
    ]);
}

afterEach(function () {
    corsEnv([]);
});

function preflight($test, string $origin, string $path = '/api/login')
{
    return $test->call('OPTIONS', $path, [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => strtolower(implode(',', FRONT_REQUEST_HEADERS)),
    ]);
}

it('accepte le domaine avec et sans www, la production Vercel et les previews du projet', function () {
    productionCors();

    foreach ([
        'https://www.waelly.com',
        'https://waelly.com',
        'https://front-trade-manager.vercel.app',
        'https://front-trade-manager-git-fix-cors-mohamxrd2s-projects.vercel.app',
        'https://front-trade-manager-a1b2c3d4e-mohamxrd2s-projects.vercel.app',
    ] as $origin) {
        $response = preflight($this, $origin);
        $response->assertNoContent();
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($origin, $origin);
        expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
        expect($response->headers->get('Access-Control-Max-Age'))->toBe('86400');
    }
});

it('refuse les autres origines, dont tout autre site Vercel et localhost en production', function () {
    productionCors();

    foreach ([
        'https://evil.example.com',
        'https://evil.vercel.app',
        'https://front-trade-manager-evil.vercel.app',
        'https://front-trade-manager-x-autre-equipe.vercel.app',
        'https://app.waelly.com',
        'http://waelly.com',
        'http://localhost:3000',
    ] as $origin) {
        expect(preflight($this, $origin)->headers->has('Access-Control-Allow-Origin'))->toBeFalse($origin);
    }
});

it('autorise tous les en-têtes envoyés par le front', function () {
    productionCors();

    $allowed = strtolower((string) preflight($this, 'https://waelly.com')->headers->get('Access-Control-Allow-Headers'));
    $allowedList = array_map('trim', explode(',', $allowed));

    foreach (FRONT_REQUEST_HEADERS as $header) {
        expect($allowedList)->toContain(strtolower($header));
    }
});

it('expose au front Retry-After et X-Request-Id', function () {
    expect(config('cors.exposed_headers'))->toContain('Retry-After', 'X-Request-Id', 'X-Account-Id');
});

it('garde l’ancienne configuration tant que CORS_ALLOWED_ORIGINS est vide', function () {
    corsEnv(['APP_ENV' => 'production', 'FRONTEND_URL' => 'https://waelly.com/', 'ADDITIONAL_CORS_ORIGINS' => 'https://app.waelly.com']);

    expect(config('cors.allowed_origins'))->toBe(['https://waelly.com', 'https://www.waelly.com', 'https://app.waelly.com']);
    expect(config('cors.allowed_origins_patterns'))->toBe([]);
});

it('rend les origines autorisées « stateful » pour Sanctum', function () {
    corsEnv(['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => 'https://waelly.com', 'SANCTUM_STATEFUL_DOMAINS' => 'api.waelly.com']);

    expect(config('sanctum.stateful'))->toBe(['api.waelly.com', 'waelly.com', 'www.waelly.com']);
});

it('n’autorise localhost qu’en local', function () {
    corsEnv(['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => 'https://waelly.com']);
    expect(config('cors.allowed_origins'))->toContain('http://localhost:3000');

    corsEnv(['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => 'https://waelly.com,http://localhost:3000']);
    expect(config('cors.allowed_origins'))->not->toContain('http://localhost:3000');
});

it('garde les en-têtes CORS sur les erreurs 422, 429 et 500', function () {
    productionCors();
    Route::middleware('api')->get('/api/__test-boom', fn () => throw new RuntimeException('boom'));
    $headers = ['Origin' => 'https://waelly.com', 'Accept' => 'application/json'];

    $this->postJson('/api/password/forgot', [], $headers)
        ->assertStatus(422)
        ->assertHeader('Access-Control-Allow-Origin', 'https://waelly.com');

    $this->getJson('/api/__test-boom', $headers)
        ->assertStatus(500)
        ->assertHeader('Access-Control-Allow-Origin', 'https://waelly.com')
        ->assertHeader('X-Request-Id');

    $last = null;
    foreach (range(1, 11) as $i) {
        $last = $this->postJson('/api/password/verify', ['email' => 'x@example.com', 'code' => '000000'], $headers);
    }
    $last->assertStatus(429)->assertHeader('Access-Control-Allow-Origin', 'https://waelly.com');
});

it('renvoie un identifiant de requête sur chaque réponse, celui du front s’il est valide', function () {
    $this->getJson('/api/health', ['X-Request-Id' => 'front-1234abcd'])->assertHeader('X-Request-Id', 'front-1234abcd');

    $generated = $this->getJson('/api/health', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id');
    expect($generated)->toMatch('/^[0-9a-f-]{36}$/');

    $this->getJson('/api/route-inexistante')->assertNotFound()->assertHeader('X-Request-Id');
});

it('/api/health répond sans toucher la base de données, avec les en-têtes CORS', function () {
    productionCors();
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    config(['database.connections.pgsql.host' => '256.256.256.256', 'database.connections.sqlite.database' => '/nonexistent/db.sqlite']);
    DB::purge();

    $this->getJson('/api/health', ['Origin' => 'https://www.waelly.com'])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertHeader('Access-Control-Allow-Origin', 'https://www.waelly.com');

    expect($queries)->toBe(0);
});

it('écrit la table des origines pour Nginx', function () {
    productionCors();

    $lines = app(\App\Console\Commands\CorsNginxMap::class)->lines();

    expect($lines)->toContain('"https://www.waelly.com" "https://www.waelly.com";', '"https://waelly.com" "https://waelly.com";')
        ->and(implode("\n", $lines))->not->toContain('localhost')
        ->and(end($lines))->toBe('"~^https://front\-trade\-manager-[a-z0-9-]+-mohamxrd2s\-projects\.vercel\.app$" $http_origin;');
});
