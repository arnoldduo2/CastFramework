<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateBaselineCommand extends MigrationCommand
{
    protected string $name = 'migrate:baseline';
    protected string $description = 'Mark the pending migrations as run WITHOUT running them (the tables already exist)';
    protected array $options = [
        '--force' => 'Allow it when APP_ENV=production (changing the database in production is refused without it)',
    ];
    protected array $examples = [
        'php cast migrate:baseline' => 'adopt migrations on an existing database',
    ];

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
