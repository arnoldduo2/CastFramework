<?php

declare(strict_types=1);

namespace Cast\Validation;

use RuntimeException;

/** Thrown by `Validator::validate()` / `$request->validate()` when input is invalid. */
final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors every message, per field */
    public function __construct(private array $errors, string $message = 'The given data was invalid.')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string, string> The first message of each field (for JSON and flashed `input_errors`). */
    public function errors(): array
    {
        return array_map(fn(array $messages) => $messages[0] ?? '', $this->errors);
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->errors;
    }
}
