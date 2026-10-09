<?php

/*
 * Application settings. Only what differs from the framework's defaults is listed here: the full list, with every default and its
 * meaning, is written by  php cast make:config app  (other sections: php cast make:config --list).
 * Values from .env: APP_NAME, APP_ENV (development | production), APP_DEBUG, APP_KEY, APP_TIMEZONE, APP_BASE_PATH (see .env.example).
 */
return [
    'namespace' => 'App',                // the PSR-4 namespace of your source folder: app/Controllers/X.php is App\Controllers\X
    'source_path' => 'app',              // where your classes live; `php cast make:controller|model|...` writes here
    'demo' => true,                      // the menu shows Log in / Register (the demo's pages); remove it when you delete the demo
    'providers' => [App\Providers\AppServiceProvider::class],   // service providers: classes that wire your services in (see AppServiceProvider)
];
