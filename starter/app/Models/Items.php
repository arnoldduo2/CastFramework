<?php

declare(strict_types=1);

namespace App\Models;

use Cast\Core\Model;

/**
 * A model is a class for one table. `Items` reads and writes the `items` table (the plural of the class name; set
 * `protected static ?string $table = 'name';` to change it). It inherits getAll(), getOne(), updateColumns(), deleteRows(),
 * paginate() and query() (a query builder that binds every value, so there is no SQL injection to think about).
 * Add your own queries as static methods, like the one below. Create one with  php cast make:model Orders
 */
class Items extends Model
{
    /** Items that are in stock, cheapest first. */
    public static function inStock(): array
    {
        return static::query()->where('qty', '>', 0)->orderBy('price')->get();
    }
}
