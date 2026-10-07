<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateResetCommand extends MigrationCommand
{
    protected string $name = 'migrate:reset';
    protected string $description = 'Undo every migration (--force in production)';

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $done = $migrator->reset();
        if ($done) $output->info(count($done) . ' migration' . (count($done) === 1 ? '' : 's') . ' rolled back.');
        return 0;
    }
}
