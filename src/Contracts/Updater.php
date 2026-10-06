<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** Tells the framework whether the app needs an update (e.g. pending migrations) and runs it. */
interface Updater
{
    public function currentVersion(): string;

    public function latestVersion(): string;

    public function needsUpdate(): bool;

    /** Run the update. Return false to report a failure. */
    public function run(): bool;
}
