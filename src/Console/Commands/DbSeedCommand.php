<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;
use Cast\Database\MigrationException;
use Cast\Database\SeederRunner;

final class DbSeedCommand extends Command
{
    protected string $name = 'db:seed';
    protected string $description = 'Run the database seeders (--class=UserSeeder for one; --force in production)';

    public function handle(Input $input, Output $output): int
    {
        if (Config::get('app.env') === 'production' && !$input->hasOption('force')) {
            $output->error('The application is in production. Add --force to run this command.');
            return 1;
        }
        try {
            $class = $input->option('class');
            $output->info('Seeded: ' . SeederRunner::run(is_string($class) ? $class : null));
            return 0;
        } catch (MigrationException $e) {
            $output->error($e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $output->error($e->getMessage());
            return 1;
        }
    }
}
