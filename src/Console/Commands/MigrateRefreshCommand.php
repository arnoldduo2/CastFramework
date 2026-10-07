<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateRefreshCommand extends MigrationCommand
{
    protected string $name = 'migrate:refresh';
    protected string $description = 'Undo every migration, then run them all again';
    protected array $options = [
        '--seed' => 'Run the seeders afterwards (config database.seeder)',
        '--force' => 'Allow it when APP_ENV=production (changing the database in production is refused without it)',
    ];
    protected array $examples = [
        'php cast migrate:refresh' => '',
    ];

    protected function seeds(): bool
    {
        return true;
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $ran = $migrator->refresh();
        $output->info(count($ran) . ' migration' . (count($ran) === 1 ? '' : 's') . ' ran.');
        return 0;
    }
}
