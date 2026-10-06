<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Contracts\ViewRenderer;
use Cast\Core\View;

/** Binds `view` (and the ViewRenderer contract) to the CastTemplate-based {@see View}. */
final class ViewProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('view', fn() => new View($this->app));
        $this->app->singleton(ViewRenderer::class, fn() => $this->app->make('view'));
    }
}
