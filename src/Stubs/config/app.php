<?php

// Application settings. Every value shown is the framework default: delete what you do not change.
return [
    'name' => env('APP_NAME', 'Cast App'),
    'env' => env('APP_ENV', 'production'),         // development | production (| testing)
    'debug' => env('APP_DEBUG', false),            // true shows error details; never in production
    'key' => env('APP_KEY', ''),                   // php cast key:generate (used by sign(), encrypt())
    'version' => env('APP_VERSION'),
    'base_path' => rtrim((string) env('APP_BASE_PATH', ''), '/'),   // URL folder when the app is not at the web root
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'namespace' => 'App',                          // PSR-4 namespace of your source folder
    'source_path' => 'app',                        // the source folder, relative to the app (php cast init uses src)
    'auto_update' => false,                        // run the Updater (config updates) when a request finds a newer version
    'error_handler' => true,                       // true | false | an options array; or put the options in config/error-handler.php
    'middleware' => [\Cast\Http\Middleware\Maintenance::class],   // run on every request
    'providers' => [],                             // your ServiceProvider classes
    // 'error_pages' => 'custom',                  // 'custom': you build resources/views/errors/{404,403,500...}; an empty one uses the framework's page
];
