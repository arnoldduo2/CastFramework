<?php

declare(strict_types=1);

namespace Cast\Database;

/** A piece of SQL that is used as it is (a default value such as CURRENT_TIMESTAMP). Never put user input in it. */
final class Raw
{
    public function __construct(public readonly string $sql) {}

    public function __toString(): string
    {
        return $this->sql;
    }
}
