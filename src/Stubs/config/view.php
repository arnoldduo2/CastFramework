<?php

// Views and components.
return [
    'ext' => env('APP_VIEWS_EXT', '.cast.php'),    // the view file extension
    'components' => 'components',                  // folder of components, inside the views folder
    'layout' => 'app',                              // the root layout a page view is wrapped in (app.cast.php); false or ['layout' => ...] per view overrides it
];
