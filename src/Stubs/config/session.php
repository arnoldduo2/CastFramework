<?php

// The session cookie. Values come from .env.
return [
    'name' => env('SESSION_NAME', 'cast_session'),
    'lifetime' => (int) env('COOKIE_LIFE', 0),     // seconds; 0 = until the browser closes
    'path' => env('COOKIE_PATH', '/'),
    'domain' => env('COOKIE_DOMAIN', ''),
    'secure' => (bool) env('COOKIE_SECURE', false),        // true when the site is served over https
    'httponly' => (bool) env('COOKIE_HTTP_ONLY', true),    // scripts cannot read the cookie
    'samesite' => env('COOKIE_SITE', 'Lax'),               // Lax | Strict | None (None needs secure)
];
