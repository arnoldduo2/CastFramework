<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\App\Application;
use Cast\Console\{Command, Input, Output};

final class VersionCommand extends Command
{
    protected string $name = 'version';
    protected string $description = 'Show the framework version';

    public function handle(Input $input, Output $output): int
    {
        $output->line('CastFramework ' . Application::VERSION . ' (PHP ' . PHP_VERSION . ')');
        return 0;
    }
}
