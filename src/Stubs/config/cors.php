<?php

// Which other sites may call this app from a browser (a front-end framework on another address). Exact origins only, never *.
return [
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
];
