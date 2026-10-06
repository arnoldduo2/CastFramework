<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

final class UpCommand extends Command
{
    protected string $name = 'up';
    protected string $description = 'Bring the app out of maintenance mode';

    public function handle(Input $input, Output $output): int
    {
        $this->app->make('maintenance')->up();
        $output->info('The application is now live.');
        return 0;
    }
}
