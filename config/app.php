<?php

/*
 * Application settings. Only what differs from the framework's defaults is listed here: the full list, with every default and its
 * meaning, is written by  php cast make:config app  (other sections: php cast make:config --list).
 * Values from .env: APP_NAME, APP_ENV (development | production), APP_DEBUG, APP_KEY, APP_TIMEZONE, APP_BASE_PATH (see .env.example).
 */
return [
    'namespace' => 'App',                // the PSR-4 namespace of your source folder: src/Controllers/X.php is App\Controllers\X
    'source_path' => 'src',            // where your classes live; `php cast make:controller|model|...` writes here
    'providers' => [],   // service providers: classes that wire your services in (see the demo's AppServiceProvider)
];
