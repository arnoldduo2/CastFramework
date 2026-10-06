<?php

declare(strict_types=1);

namespace Cast\Core\Updates;

use Cast\Contracts\Updater;
use Closure;

/** An {@see Updater} built from closures: current version, latest version, and the update step. */
final class CallbackUpdater implements Updater
{
    public function __construct(private Closure $current, private Closure $latest, private Closure $run) {}

    public function currentVersion(): string
    {
        return (string) ($this->current)();
    }

    public function latestVersion(): string
    {
        return (string) ($this->latest)();
    }

    public function needsUpdate(): bool
    {
        return version_compare($this->currentVersion(), $this->latestVersion(), '<');
    }

    public function run(): bool
    {
        return (bool) ($this->run)();
    }
}
