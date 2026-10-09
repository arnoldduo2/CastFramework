<?php

declare(strict_types=1);

/*
 * Your own helper functions. Every *.php file in this folder is loaded at boot (config/helpers.php says which folder), so the function
 * can be used anywhere, including in views:  <?= price(12.5) ?>  The `function_exists` guard lets an app replace a framework helper.
 * Business rules (tax, document numbers...) belong here or in a service, not in the framework.
 */
if (!function_exists('price')) {
    function price(float|int|string $amount): string
    {
        return __money($amount, '$');
    }
}
