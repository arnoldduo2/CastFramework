<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** A custom validation rule. Pass an instance in a rule list: `['name' => ['required', new MyRule()]]`. */
interface Rule
{
    /** @param array<string, mixed> $data All the data being validated. */
    public function passes(string $field, mixed $value, array $data): bool;

    /** The error message; `:field` is replaced with the field label. */
    public function message(): string;
}
