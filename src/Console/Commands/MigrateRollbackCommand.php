<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateRollbackCommand extends MigrationCommand
{
    protected string $name = 'migrate:rollback';
    protected string $description = 'Undo the last batch of migrations';
    protected array $options = [
        '--step=N' => 'Undo the last N migrations instead of the last batch',
        '--pretend' => 'Print the SQL instead of running it',
        '--force' => 'Allow it when APP_ENV=production (changing the database in production is refused without it)',
    ];
    protected array $examples = [
        'php cast migrate:rollback' => 'last batch',
        'php cast migrate:rollback --step=2' => 'last two',
    ];

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $step = $input->hasOption('step') ? max(1, (int) $input->option('step', 1)) : null;
        $pretend = $input->hasOption('pretend');
        $done = $migrator->rollback($step, $pretend);
        if ($pretend) $this->printPretended($migrator->pretended(), $output);
        elseif ($done) $output->info(count($done) . ' migration' . (count($done) === 1 ? '' : 's') . ' rolled back.');
        return 0;
    }
}
