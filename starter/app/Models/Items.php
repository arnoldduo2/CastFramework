<?php

declare(strict_types=1);

namespace App\Models;

use Cast\Core\Model;

class Items extends Model
{
    /** Items that are in stock, cheapest first. */
    public static function inStock(): array
    {
        return static::query()->where('qty', '>', 0)->orderBy('price')->get();
    }
}
