<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateBaselineCommand extends MigrationCommand
{
    protected string $name = 'migrate:baseline';
    protected string $description = 'Mark the pending migrations as run WITHOUT running them (for tables that already exist) (--force in production)';

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $names = $migrator->baseline();
        if (!$names) {
            $output->line('Nothing to record.');
            return 0;
        }
        $output->info(count($names) . ' migration' . (count($names) === 1 ? '' : 's') . ' recorded as run. Future migrations will run normally.');
        return 0;
    }
}
