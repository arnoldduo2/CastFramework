<?php

/**
 * Global helper functions, loaded by Composer (`autoload.files`). One file per category:
 *
 *   core      app, env, paths, views/components/modules, routing, alerts, debugging
 *   request   request(), response(), getPost()
 *   security  CSRF, passwords, tokens, current user
 *   strings   text helpers
 *   arrays    array helpers
 *   dates     date helpers
 *   math      numbers and money formatting
 *   html      escaping and attribute helpers
 *   validation  param and form validation helpers
 *
 * Every function is wrapped in `function_exists`, so an app can define its own version first.
 * App-specific helpers (business logic) live in the app: point `config('helpers.custom')` at a folder of
 * `*.php` files and they are loaded when the application boots.
 */

foreach (['core', 'request', 'security', 'strings', 'arrays', 'dates', 'math', 'html', 'validation'] as $__cast_helpers) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . $__cast_helpers . '.php';
}
unset($__cast_helpers);
