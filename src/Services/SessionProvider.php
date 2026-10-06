<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Core\Session;

/** Starts the session and binds the default session-based `guard` (an app can bind its own after this runs). */
final class SessionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('guard', fn() => new SessionGuard());
    }

    public function boot(): void
    {
        Session::init();
    }
}
