<?php

declare(strict_types=1);

namespace Cast\Services;

use RuntimeException;

/** The record being saved already exists. Models may throw it; {@see ModelPersister} throws it for the legacy `'duplicate'` result. */
final class DuplicateEntryException extends RuntimeException
{
    public function __construct(string $message = 'This entry has already been saved.')
    {
        parent::__construct($message, 409);
    }
}
