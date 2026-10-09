<?php

// Database connection and migrations. Values come from .env (DB_CONN, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
return [
    'driver' => rtrim((string) env('DB_CONN', 'mysql'), ':'),    // mysql | pgsql | sqlite
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', ''),
    'name' => env('DB_NAME', ''),                  // for sqlite: the file, e.g. storage/database.sqlite
    'user' => env('DB_USER', ''),
    'pass' => env('DB_PASS', ''),
    'charset' => 'utf8mb4',
    'connection' => null,                          // a callable returning a PDO: let another ORM own the connection (docs/ORM-ADAPTERS.md)
    'migrations' => ['table' => 'migrations', 'path' => null],   // path null = database/migrations
    'seeder' => 'DatabaseSeeder',
];
