<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Maintenance\MaintenanceManager;

final class DownCommand extends Command
{
    protected string $name = 'down';
    protected string $description = 'Put the app in maintenance mode (a 503 page for everyone except those with the secret)';
    protected array $options = [
        '--message=TEXT' => 'The message shown on the maintenance page',
        '--secret=WORD' => 'A secret that lets you through (?maintenance_secret=WORD or header X-Maintenance-Secret); stored hashed',
        '--retry=SECONDS' => 'Sends a Retry-After header',
        '--in=SECONDS' => 'Do not go down now: go down automatically after this many seconds (a countdown)',
    ];
    protected array $examples = [
        'php cast down --message="Back at noon" --secret=letmein --retry=120' => 'down now',
        'php cast down --in=60' => 'down in a minute',
    ];

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
