<?php

declare(strict_types=1);

/*
 * The JSON API. Every route here is served under /api (config 'api.prefix'), and every response, errors included, is JSON:
 * {status, msg, data}. It uses the same Router as routes/web.php:
 *   Router::get('/things', [ThingsController::class, 'index']);
 *   ->use([Throttle::class, 10, 1])               limit to 10 requests a minute
 *   Router::middleware([ApiAuth::class, 'things:read'], fn() => ...)   a bearer token (or a login session) with that ability
 * Tokens: php cast token:create admin@example.com --abilities=items:read
 */

use App\Controllers\Api\ItemsController;
use App\Controllers\Api\TokenController;
use Cast\Core\Router;
use Cast\Http\Middleware\ApiAuth;
use Cast\Http\Middleware\Throttle;

// Get a token with an email and password (no session, no CSRF token needed). Rate limited: 10 a minute per address.
Router::post('/auth/token', [TokenController::class, 'issue'])->use([Throttle::class, 10, 1]);

// A valid token (or a login session on the same site) is required below
Router::middleware([ApiAuth::class], function () {
    Router::get('/me', [TokenController::class, 'me']);
    Router::delete('/auth/token', [TokenController::class, 'revoke']);

    Router::group('/items', function () {
        // a token can be limited to some of these with abilities: items:read, items:write
        Router::middleware([ApiAuth::class, 'items:read'], function () {
            Router::get('/', [ItemsController::class, 'index']);
            Router::get('/{id}', [ItemsController::class, 'show']);
        });
        Router::middleware([ApiAuth::class, 'items:write'], function () {
            Router::post('/', [ItemsController::class, 'store']);
            Router::put('/{id}', [ItemsController::class, 'update']);
            Router::delete('/{id}', [ItemsController::class, 'destroy'])->middleware(['manage-items']);
        });
    });
});
