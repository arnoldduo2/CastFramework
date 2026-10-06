<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** A model that can update an existing record from request data. */
interface Patchable
{
    /** @return int|string|false The record id/number that was updated, or false on failure. */
    public function patch(array $data): int|string|false;
}
