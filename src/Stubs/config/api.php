<?php

// The JSON API: routes/api.php is served under this prefix; errors are always JSON.
return [
    'prefix' => '/api',
    'middleware' => [],                            // middleware for every API route, e.g. [[Cast\Http\Middleware\Throttle::class, 60, 1]]
    'tokens' => ['table' => 'api_tokens'],         // php cast token:schema --migration
];
