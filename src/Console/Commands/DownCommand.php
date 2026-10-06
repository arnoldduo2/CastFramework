<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Maintenance\MaintenanceManager;

final class DownCommand extends Command
{
    protected string $name = 'down';
    protected string $description = 'Put the app in maintenance mode (--message= --secret= --retry=SECONDS --in=SECONDS)';

    public function handle(Input $input, Output $output): int
    {
        /** @var MaintenanceManager $maintenance */
        $maintenance = $this->app->make('maintenance');
        $message = (string) $input->option('message', '');

        if ($input->hasOption('in')) {
            $seconds = (int) $input->option('in');
            $maintenance->schedule($seconds, $message);
            $output->info("Maintenance scheduled in $seconds seconds.");
            return 0;
        }

        $secret = $input->option('secret');
        $maintenance->down($message, is_string($secret) ? $secret : null, (int) $input->option('retry', 0));
        $output->info('The application is now in maintenance mode.');
        if (is_string($secret)) $output->line("Bypass with the header X-Maintenance-Secret or ?maintenance_secret=$secret");
        return 0;
    }
}
