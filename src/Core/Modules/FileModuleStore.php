<?php

declare(strict_types=1);

namespace Cast\Core\Modules;

use Cast\Contracts\ModuleStore;

/** Module switches in a JSON file (default storage/framework/modules.json), changed with `php cast modules:enable|disable`. */
final class FileModuleStore implements ModuleStore
{
    public function __construct(private string $file) {}

    public function isEnabled(string $module): ?bool
    {
        $data = $this->read();
        return array_key_exists($module, $data) ? (bool) $data[$module] : null;
    }

    public function set(string $module, bool $enabled): void
    {
        $data = $this->read();
        $data[$module] = $enabled;
        if (!is_dir(dirname($this->file))) mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** @return array<string, bool> */
    private function read(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];
        return is_array($data) ? $data : [];
    }
}
