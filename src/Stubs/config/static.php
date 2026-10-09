<?php

// Static files served by the framework (answered before the app boots): URL prefix => where the files are.
// `dir` is relative to the app; `keep_prefix` keeps the first URL segment in the path (/css/app.css => {resources}/css/app.css).
return [
    'css' => ['dir' => '{resources}', 'keep_prefix' => true],
    'js' => ['dir' => '{resources}', 'keep_prefix' => true],
    'styles' => ['dir' => 'public/assets/css', 'keep_prefix' => false],
    'fonts' => ['dir' => 'public/assets/fonts', 'keep_prefix' => false],
    'images' => ['dir' => 'public/assets/images', 'keep_prefix' => false],
    'public' => ['dir' => 'public/assets/vendor', 'keep_prefix' => false],
    'cast' => ['path' => \Cast\App\Application::instance()->frameworkPath('Resources'), 'keep_prefix' => false],   // /cast/cast.module.js and /cast/cast.css (the SPA client)
    // The framework's documentation viewer at /cdocs (the name leaves /docs for your own app). Development only (cdocs.enabled = true in config/cdocs.php turns it on in production).
    // To show your own documentation at /docs, build it into public/docs with `php cast docs:build`: the web server serves public/ files itself.
    'cdocs' => ['path' => \Cast\App\Application::instance()->frameworkPath('Resources/docs'), 'keep_prefix' => false, 'index' => 'index.html', 'dev_only' => true],
];
