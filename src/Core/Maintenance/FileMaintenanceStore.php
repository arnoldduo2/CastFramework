<?php

declare(strict_types=1);

namespace Cast\Core\Maintenance;

use Cast\Contracts\MaintenanceStore;

/** Keeps maintenance state in a JSON file (default: storage/framework/maintenance.json). */
final class FileMaintenanceStore implements MaintenanceStore
{
    public function __construct(private string $file) {}

    public function read(): array
    {
        if (!is_file($this->file)) return [];
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    public function write(array $config): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($this->file, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function clear(): void
    {
        if (is_file($this->file)) unlink($this->file);
    }
}
