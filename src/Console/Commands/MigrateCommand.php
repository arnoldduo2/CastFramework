<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateCommand extends MigrationCommand
{
    protected string $name = 'migrate';
    protected string $description = 'Run the pending migrations';
    protected array $options = [
        '--seed' => 'Run the seeders afterwards',
        '--pretend' => 'Print the SQL instead of running it',
        '--step' => 'Give each migration its own batch, so they can be rolled back one at a time',
        '--force' => 'Allow it when APP_ENV=production (changing the database in production is refused without it)',
    ];
    protected array $examples = [
        'php cast migrate' => 'run all pending',
        'php cast migrate --pretend' => 'see the SQL first',
        'php cast migrate --seed' => 'and seed',
    ];

    protected function seeds(): bool
    {
        return true;
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $pretend = $input->hasOption('pretend');
        $ran = $migrator->migrate(['step' => $input->hasOption('step'), 'pretend' => $pretend]);
        if ($pretend) $this->printPretended($migrator->pretended(), $output);
        elseif ($ran) $output->info(count($ran) . ' migration' . (count($ran) === 1 ? '' : 's') . ' ran.');
        return 0;
    }
}
