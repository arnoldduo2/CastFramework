<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** A model that can create a record from request data. See {@see \Cast\Services\ModelPersister}. */
interface Savable
{
    /** @return int|string|false The new record id/number, or false on failure. */
    public function save(array $data): int|string|false;
}
