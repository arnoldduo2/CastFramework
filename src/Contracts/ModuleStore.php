<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * Where "is this module switched on?" is remembered when it can change while the app runs (a settings page, an admin switch).
 * The default is a JSON file; an app with a database table of modules implements this and binds it as `module_store`.
 */
interface ModuleStore
{
    /** true / false when the store has an opinion about the module, null to fall back to the config. */
    public function isEnabled(string $module): ?bool;

    public function set(string $module, bool $enabled): void;
}
