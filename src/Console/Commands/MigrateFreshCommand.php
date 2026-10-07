<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateFreshCommand extends MigrationCommand
{
    protected string $name = 'migrate:fresh';
    protected string $description = 'Drop ALL tables, then run every migration (--seed --force)';

    protected function seeds(): bool
    {
        return true;
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $ran = $migrator->fresh();
        $output->info(count($ran) . ' migration' . (count($ran) === 1 ? '' : 's') . ' ran.');
        return 0;
    }
}
