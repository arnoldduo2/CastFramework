<?php

// Module gating (opt in, off by default). List the parts of your app and put each group of routes behind its gate:
//     Router::module('reports', function () { Router::get('/reports', ReportsController::class); });
// A module that is switched off, not built yet or not listed answers with a "module inactive or unavailable" page (JSON for APIs) instead of breaking the app.
// Menus can hide an item with  module_active('reports').  Commands: modules:list, modules:check, modules:enable|disable <name>.
return [
    'enabled' => env('CAST_MODULES', false),     // true (or CAST_MODULES=true in .env) turns the gates on

    // Core: the app cannot work without them. modules:check and deploy:check fail when one is missing; they cannot be switched off.
    'core' => [
        // 'auth',
        // 'users' => ['title' => 'Users', 'requires' => [App\Controllers\UsersController::class]],
    ],

    // Optional: the app works without them; a missing one only shows the fallback page for its routes.
    'optional' => [
        // 'reports',
        // 'printing' => ['title' => 'Receipt printing', 'requires' => [App\Services\PrinterService::class]],   // unbuilt until the class exists
        // 'barcodes' => ['active' => false],                                            // switched off; true, false or a function returning bool
    ],
];
