<?php

// Module gating (opt in, off by default). List the parts of your app and put each group of routes behind its gate:
//     Router::module('reports', function () { Router::get('/reports', ReportsController::class); });
// A module that is switched off, not built yet or not listed answers with a "module inactive or unavailable" page (JSON for APIs) instead of breaking the app.
// Menus can hide an item with  module_active('reports').  Commands: modules:list, modules:check, modules:enable|disable <name>.
return [
    'enabled' => env('CAST_MODULES', false),     // true (or CAST_MODULES=true in .env) turns the gates on

    // Where on/off switches are kept: 'file' (storage/framework/modules.json) or 'database' (the table below; php cast modules:table --migration).
    // Or bind your own Cast\Contracts\ModuleStore as 'module_store' in a provider.
    'store' => 'file',
    'table' => 'modules',

    // Plans, licences or tenant rules are your app's business, not the framework's: add them with app('modules')->resolveUsing(fn(array $m) => ...)
    // in a service provider (see the README, "Modules"). Any extra key in a module's array below is passed to it as $m['options'].

    // Core: the app cannot work without them. modules:check and deploy:check fail when one is missing; they cannot be switched off.
    'core' => [
        // 'auth',
        // 'users' => ['title' => 'Users', 'requires' => [App\Controllers\UsersController::class]],
    ],

    // Optional: the app works without them; a missing one only shows the fallback page for its routes.
    'optional' => [
        // 'reports',
        // 'printing' => ['title' => 'Receipt printing', 'requires' => [App\Services\PrinterService::class]],   // unbuilt until the class exists
        // 'payroll' => ['tier' => 'professional'],                                      // 'tier' means nothing to the framework: your resolveUsing() rule can read it
        // 'barcodes' => ['active' => false],                                            // switched off; true, false or a function returning bool
    ],
];
