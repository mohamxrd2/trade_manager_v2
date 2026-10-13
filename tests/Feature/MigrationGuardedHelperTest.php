<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tests de l'instrumentation temporaire de
| 2026_10_12_000001_add_idempotency_key_unique_constraint_to_transactions_table.php
| (voir le docblock de ce fichier pour le contexte : SQLSTATE[25P02]
| observé en production sur cette migration, jamais reproduit en local).
|
| À supprimer avec ce même fichier une fois l'instrumentation retirée.
|--------------------------------------------------------------------------
*/

function loadIdempotencyConstraintMigration()
{
    return require base_path('database/migrations/2026_10_12_000001_add_idempotency_key_unique_constraint_to_transactions_table.php');
}

beforeEach(function () {
    Schema::dropIfExists('transactions');
    Schema::create('transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('type');
        $table->timestamps();
    });
});

test('guarded() journalise une erreur puis relance l\'exception d\'origine sans l\'absorber', function () {
    $migration = loadIdempotencyConstraintMigration();
    $guarded = new ReflectionMethod($migration, 'guarded');
    $guarded->setAccessible(true);

    $messages = [];
    Event::listen(MessageLogged::class, function ($event) use (&$messages) {
        $messages[] = $event;
    });

    $thrown = null;
    try {
        $guarded->invoke($migration, '[TEST]', 'étape simulée', function () {
            throw new \RuntimeException('échec simulé pour le test');
        });
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    // L'exception d'origine ressort inchangée, pas une exception différente
    // ou "avalée".
    expect($thrown)->not->toBeNull();
    expect($thrown)->toBeInstanceOf(\RuntimeException::class);
    expect($thrown->getMessage())->toBe('échec simulé pour le test');

    // Une ligne d'erreur a bien été journalisée pour cette étape précise.
    $errorLogs = array_filter($messages, fn ($e) => $e->level === 'error' && str_contains($e->message, 'étape simulée'));
    expect($errorLogs)->not->toBeEmpty();
});

test('sqlState() préfère errorInfo[0] à getCode() quand ils divergent (erreur de connexion)', function () {
    $migration = loadIdempotencyConstraintMigration();
    $sqlState = new ReflectionMethod($migration, 'sqlState');
    $sqlState->setAccessible(true);

    // Reproduit exactement la forme observée empiriquement (local, PG18.6,
    // mauvais mot de passe) : getCode() = 7 (code interne du pilote),
    // errorInfo[0] = '08006' (le vrai SQLSTATE). sqlState() doit renvoyer
    // '08006', pas 7.
    $pdoException = new \PDOException('connection to server ... failed: FATAL: password authentication failed', 7);
    $pdoException->errorInfo = ['08006', 7, 'connection to server ... failed: FATAL: password authentication failed'];
    $queryException = new \Illuminate\Database\QueryException('pgsql', 'SELECT 1', [], $pdoException);

    expect($queryException->getCode())->toBe(7);
    expect($sqlState->invoke($migration, $queryException))->toBe('08006');

    // Cas où ils concordent (erreur de requête) : doit aussi fonctionner.
    $pdoException2 = new \PDOException('type does not exist', '42704');
    $pdoException2->errorInfo = ['42704', 7, 'ERROR: type "x" does not exist'];
    $queryException2 = new \Illuminate\Database\QueryException('pgsql', 'ALTER TABLE ...', [], $pdoException2);

    expect($sqlState->invoke($migration, $queryException2))->toBe('42704');

    // Exception sans rapport avec PDO : repli sur getCode().
    $generic = new \RuntimeException('erreur applicative', 123);
    expect($sqlState->invoke($migration, $generic))->toBe('123');
});

/**
 * Tente une connexion PostgreSQL réelle et jetable pour le test qui suit —
 * gracieusement ignoré si aucune base PostgreSQL n'est disponible à cette
 * adresse (ex: environnement CI sans conteneur Docker). Les valeurs par
 * défaut correspondent au conteneur jetable utilisé pour valider ce
 * correctif (postgres:18).
 */
function configurePgsqlDiagConnectionOrSkip(): bool
{
    config(['database.connections.pgsql_diag' => [
        'driver' => 'pgsql',
        'host' => env('DIAG_PGSQL_HOST', '127.0.0.1'),
        'port' => env('DIAG_PGSQL_PORT', '55432'),
        'database' => env('DIAG_PGSQL_DATABASE', 'diagdb'),
        'username' => env('DIAG_PGSQL_USERNAME', 'postgres'),
        'password' => env('DIAG_PGSQL_PASSWORD', 'diagpass'),
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'disable',
    ]]);

    try {
        DB::connection('pgsql_diag')->select('SELECT 1');

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

test('sur PostgreSQL réel : une violation de contrainte identifiable (23505) est capturée, relancée telle quelle, et aucune étape suivante ne s\'exécute', function () {
    if (!configurePgsqlDiagConnectionOrSkip()) {
        test()->markTestSkipped('Nécessite un PostgreSQL jetable sur 127.0.0.1:55432 (voir configurePgsqlDiagConnectionOrSkip).');
    }

    $connection = DB::connection('pgsql_diag');
    $schema = $connection->getSchemaBuilder();

    $schema->dropIfExists('transactions');
    $schema->create('transactions', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('idempotency_key')->nullable();
        $table->string('type');
        $table->timestamps();
    });

    // Deux lignes avec la MÊME paire (user_id, idempotency_key) non nulle :
    // la contrainte unique que la migration va tenter d'ajouter ne pourra
    // donc pas être posée — violation réelle, identifiable, déterministe
    // (23505 : unique_violation), sans artifice de rôle ou de privilège.
    $userId = (string) \Illuminate\Support\Str::uuid();
    $key = (string) \Illuminate\Support\Str::uuid();
    $connection->table('transactions')->insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $userId, 'idempotency_key' => $key, 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $userId, 'idempotency_key' => $key, 'type' => 'expense', 'created_at' => now(), 'updated_at' => now()],
    ]);

    config(['database.default' => 'pgsql_diag']);

    $messages = [];
    Event::listen(MessageLogged::class, function ($event) use (&$messages) {
        $messages[] = $event;
    });

    $migration = loadIdempotencyConstraintMigration();

    $thrown = null;
    try {
        $migration->up();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    // L'exception a bien été relancée, pas absorbée.
    expect($thrown)->not->toBeNull();
    expect($thrown)->toBeInstanceOf(\Illuminate\Database\QueryException::class);

    // Le SQLSTATE capturé dans le log correspond exactement à la vraie
    // violation (23505), extrait via errorInfo[0] — pas un artefact de
    // getCode().
    $failureLog = collect($messages)->first(fn ($e) => $e->level === 'error' && str_contains($e->message, 'ADD CONSTRAINT'));
    expect($failureLog)->not->toBeNull();
    expect($failureLog->message)->toContain('SQLSTATE=23505');

    // Aucune étape suivante n'a été tentée : pas de ligne de succès final,
    // et rien n'a été journalisé après la ligne d'échec elle-même.
    $hasSuccessLog = collect($messages)->contains(fn ($e) => str_contains($e->message, 'migration terminée avec succès'));
    expect($hasSuccessLog)->toBeFalse();

    $failureIndex = array_search($failureLog, $messages, true);
    expect($failureIndex)->toBe(count($messages) - 1);
});
