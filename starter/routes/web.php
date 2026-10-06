<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ItemsController;
use Cast\Core\Router;
use Cast\Http\Middleware\Authenticate;

// Anyone
Router::get('/', HomeController::class);

// Guests only (signed-in users are sent to auth.home_path)
Router::middleware([Authenticate::class, 'auth'], function () {
    Router::get('/login', [AuthController::class, 'login']);
    Router::post('/login', [AuthController::class, 'attempt']);
});

// Signed-in users only
Router::middleware([Authenticate::class, 'private'], function () {
    Router::post('/logout', [AuthController::class, 'logout']);

    Router::group('/items', function () {
        Router::get('/', ItemsController::class);                       // list + form
        Router::post('/', [ItemsController::class, 'store']);
        Router::put('/{id}', [ItemsController::class, 'update']);
        Router::delete('/{id}', [ItemsController::class, 'destroy'])->middleware(['manage-items']);
    });
});

// A route that fails, to see the error page: /_boom
Router::get('/_boom', fn() => abort(500, 'This is what a server error looks like.'));
