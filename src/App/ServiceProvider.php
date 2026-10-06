<?php

declare(strict_types=1);

namespace Cast\App;

/**
 * Base class for service providers. `register()` binds things into the container;
 * `boot()` runs after every provider has registered.
 */
abstract class ServiceProvider
{
    public function __construct(protected Application $app) {}

    public function register(): void {}

    public function boot(): void {}
}
