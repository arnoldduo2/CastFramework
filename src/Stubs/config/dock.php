<?php

// The Cast dock: a floating button (bottom right) with links to the demo and the documentation while you build.
// The framework adds it to HTML pages itself, so it is still there if you delete your layout. Development only.
return [
    'enabled' => env('CAST_DOCK', true),    // false (or CAST_DOCK=false in .env) turns it off for good
    'demo_url' => '/demo',                  // where the "Demo the Cast Framework" link goes
];
