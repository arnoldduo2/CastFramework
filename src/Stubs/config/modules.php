<?php

// Module gating (opt in, off by default). List the parts of your app and put each group of routes behind its gate:
//     Router::module('reports', function () { Router::get('/reports', ReportsController::class); });
// A module that is switched off, not built yet or not listed answers with a "module inactive or unavailable" page (JSON for APIs) instead of breaking the app.
// Menus can hide an item with  module_active('reports').  Commands: modules:list, modules:check, modules:enable|disable <name>.
return [
    'enabled' => env('CAST_MODULES', false),     // true (or CAST_MODULES=true in .env) turns the gates on

    // Where on/off switches (and the plan) are kept: 'file' (storage/framework/modules.json) or 'database' (the table below; php cast modules:table --migration).
    // Or bind your own Cast\Contracts\ModuleStore as 'module_store' in a provider.
    'store' => 'file',
    'table' => 'modules',

    // Plans (tiers), lowest first. Empty = no plans. A plan includes every tier below it. Core modules are always in the first tier.
    // The install's plan: CAST_TIER in .env, php cast modules:tier professional (kept in the store), or a function returning the name
    // (read it from a licence or tenant row):  'tier' => fn() => app('licence')->plan(),
    'tiers' => [],                                  // e.g. ['essentials', 'professional', 'enterprise']
    'tier' => env('CAST_TIER'),
    'upgrade_url' => '',                            // "See the plans" link on the page for a module the plan does not include

    // Core: the app cannot work without them. modules:check and deploy:check fail when one is missing; they cannot be switched off.
    'core' => [
        // 'auth',
        // 'users' => ['title' => 'Users', 'requires' => [App\Controllers\UsersController::class]],
    ],

    // Optional: the app works without them; a missing one only shows the fallback page for its routes.
    'optional' => [
        // 'reports',
        // 'printing' => ['title' => 'Receipt printing', 'requires' => [App\Services\PrinterService::class]],   // unbuilt until the class exists
        // 'payroll' => ['tier' => 'professional'],                                      // part of the Professional plan and above
        // 'barcodes' => ['active' => false],                                            // switched off; true, false or a function returning bool
    ],
];
