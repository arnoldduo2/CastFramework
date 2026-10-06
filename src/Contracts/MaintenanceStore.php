<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** Where the maintenance-mode state is kept (the framework default is a JSON file). */
interface MaintenanceStore
{
    /** @return array<string, mixed> The stored config, or [] when none. */
    public function read(): array;

    /** @param array<string, mixed> $config */
    public function write(array $config): void;

    public function clear(): void;
}
