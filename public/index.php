<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// PHP 8.5+ déprécie des constantes PDO encore utilisées par Laravel et par
// config/database.php (ex: PDO::MYSQL_ATTR_SSL_CA, PDO::PGSQL_ATTR_DISABLE_PREPARES).
// Sans ceci, ces avertissements s'impriment en HTML brut au tout début de
// CHAQUE réponse HTTP (avant même le JSON de l'API), la corrompant pour le
// frontend. N'affecte pas la journalisation Laravel des vraies exceptions.
error_reporting(E_ALL & ~E_DEPRECATED);

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
