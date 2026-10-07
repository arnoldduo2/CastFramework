<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateStatusCommand extends MigrationCommand
{
    protected string $name = 'migrate:status';
    protected string $description = 'Show which migrations have run';

    protected function allowed(Input $input, Output $output): bool
    {
        return true;   // read only
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $rows = $migrator->status();
        if (!$rows) {
            $output->line('No migrations found.');
            return 0;
        }
        $output->table(['Ran?', 'Migration', 'Batch'], array_map(
            fn($r) => [$r['ran'] ? 'Yes' : 'No', $r['migration'], $r['batch'] === null ? '' : (string) $r['batch']],
            $rows
        ));
        return 0;
    }
}
