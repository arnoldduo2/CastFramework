<?php

declare(strict_types=1);

/**
 * The front controller: the only file the web server runs. Every request ends up here (see .htaccess for Apache; `php cast serve`
 * does the same for the built-in server). It loads Composer, builds the application and lets it answer the request.
 * Do not change this file unless you know what you are doing; point your web server's document root at THIS folder (public/), never at the project root.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';   // creates the Application (paths, .env, config)
$app->run();                                         // boots the providers, runs the middleware and the router, sends the response
