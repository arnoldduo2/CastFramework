<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MakeMigrationCommand extends MigrationCommand
{
    protected string $name = 'make:migration';
    protected string $description = 'Create a migration file in database/migrations';
    protected array $arguments = [
        'name' => 'snake_case name; create_orders_table and add_x_to_orders_table pick the right stub',
    ];
    protected array $options = [
        '--create=TABLE' => 'Use the create-table stub for this table',
        '--table=TABLE' => 'Use the change-table stub for this table',
    ];
    protected array $examples = [
        'php cast make:migration create_orders_table' => 'create stub',
        'php cast make:migration add_status_to_orders_table' => 'change stub',
        'php cast make:migration fix_data --table=orders' => 'explicit table',
    ];

    protected function allowed(Input $input, Output $output): bool
    {
        return true;   // only writes a file
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $name = (string) $input->argument(0, '');
        if ($name === '') {
            $output->error('Usage: php cast make:migration <name> [--create=table | --table=table]   e.g. create_orders_table, add_status_to_orders_table');
            return 1;
        }
        $create = $input->option('create');
        $table = $input->option('table');
        $path = $migrator->make($name, is_string($create) ? $create : null, is_string($table) ? $table : null);
        $output->info('Created ' . str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $path));
        return 0;
    }
}
