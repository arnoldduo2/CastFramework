<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

/** `php cast deploy:optimize`: clears compiled views (the first visit compiles them again with the live PHP) and lists the speed-ups to do on the server. */
final class DeployOptimizeCommand extends Command
{
    protected string $name = 'deploy:optimize';
    protected string $description = 'Clear compiled views and list the production speed-ups (autoloader, OPcache)';
    protected array $examples = [
        'php cast deploy:optimize' => 'run after each deploy',
    ];

    public function handle(Input $input, Output $output): int
    {
        (new ViewsClearCommand($this->app))->handle($input, $output);
        $output->line();
        $output->line($output->color('Also, on the server:', 'orange'));
        foreach ([
            'composer install --no-dev --optimize-autoloader --classmap-authoritative',
            'php.ini: opcache.enable=1, opcache.validate_timestamps=0 (then reload PHP-FPM after each deploy)',
            'php cast deploy:check',
        ] as $line) $output->line('  ' . $line);
        return 0;
    }
}
