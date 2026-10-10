<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Core\Config;
use Cast\Core\Modules\{DatabaseModuleStore, FileModuleStore, Modules};

/** Binds `modules` (the registry in config/modules.php) and `module_store`: a JSON file, or a database table with 'store' => 'database'. Bind your own `module_store` to use another. */
final class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('module_store', fn() => Config::get('modules.store', 'file') === 'database'
            ? new DatabaseModuleStore((string) Config::get('modules.table', 'modules'))
            : new FileModuleStore($this->app->storagePath('framework/modules.json')));
        $this->app->singleton('modules', fn() => new Modules($this->app->make('module_store')));
    }
}
