<?php

declare(strict_types=1);

// An example of an app-owned helper: business logic stays out of the framework.
if (!function_exists('price')) {
    function price(float|int|string $amount): string
    {
        return __money($amount, '$');
    }
}
