<?php

/**
 * Proxy for subdirectory hosting.
 * Allows the app to run from /abuseai/ instead of /abuseai/public/.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$publicPath = __DIR__.'/public';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel
/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

// Override the public path to point to /public
$app->usePublicPath($publicPath);

// Handle the request
$app->handleRequest(Request::capture());
