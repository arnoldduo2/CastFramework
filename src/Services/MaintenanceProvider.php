<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Core\Config;
use Cast\Core\Maintenance\FileMaintenanceStore;
use Cast\Core\Maintenance\MaintenanceManager;

/** Binds `maintenance` (file-backed by default; `config('maintenance.bypass')` can let some requests through). */
final class MaintenanceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('maintenance', function () {
            $bypass = Config::get('maintenance.bypass');
            return new MaintenanceManager(
                new FileMaintenanceStore($this->app->storagePath('framework/maintenance.json')),
                is_callable($bypass) ? \Closure::fromCallable($bypass) : null
            );
        });
    }
}
