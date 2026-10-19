<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou exécuté avant CHAQUE test : si la connexion de test n'est pas
     * SQLite (ex: Postgres, via phpunit.pgsql.xml), le nom de la base DOIT se
     * terminer par "_test" — sinon le test échoue immédiatement avec un
     * message clair, avant d'exécuter la moindre requête. Protège contre un
     * phpunit.*.xml mal configuré qui pointerait vers la base de
     * développement ou vers waelly_staging : aucune suite de tests ne doit
     * jamais pouvoir écrire dans l'une ou l'autre.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');

        if ($connection === 'sqlite') {
            return;
        }

        $database = config("database.connections.{$connection}.database");

        if (!is_string($database) || !str_ends_with($database, '_test')) {
            self::fail(
                "Garde-fou tests : la base configurée (\"{$database}\", connexion \"{$connection}\") ne se termine pas par \"_test\". " .
                "Les tests refusent de démarrer pour ne jamais risquer de toucher une base de développement ou de staging."
            );
        }
    }
}
