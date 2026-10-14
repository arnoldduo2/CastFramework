<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use Cast\Core\Router;

/*
 * The app's pages. Every *.php file in this folder is loaded; routes/api.php (if you add one) is served under /api.
 *
 *   Router::get('/orders', [OrdersController::class, 'index']);        a method of a controller
 *   Router::get('/orders', OrdersController::class);                  a controller with an index() method
 *   Router::post('/orders', ...); Router::put('/orders/{id}', ...);    every verb: get post put patch delete
 *   Router::group('/admin', function () { ... });                      a prefix for the routes inside
 *   Router::middleware([Authenticate::class, 'private'], function () { ... });   a rule that must pass first
 *
 * List them all with  php cast route:list
 */
Router::get('/', HomeController::class);
Router::get('/demo', [HomeController::class, 'demo']);
