<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;

final class MigrateCommand extends MigrationCommand
{
    protected string $name = 'migrate';
    protected string $description = 'Run the pending migrations (--seed --pretend --step --force)';

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
