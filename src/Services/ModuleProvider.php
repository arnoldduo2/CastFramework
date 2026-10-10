<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Core\Modules\{FileModuleStore, Modules};

/** Binds `modules` (the registry in config/modules.php) and, unless you bind your own `module_store`, a JSON file store for switches. */
final class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('module_store', fn() => new FileModuleStore($this->app->storagePath('framework/modules.json')));
        $this->app->singleton('modules', fn() => new Modules($this->app->make('module_store')));
    }
}
