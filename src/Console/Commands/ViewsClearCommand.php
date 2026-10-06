<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

final class ViewsClearCommand extends Command
{
    protected string $name = 'views:clear';
    protected string $description = 'Delete the compiled view cache';

    public function handle(Input $input, Output $output): int
    {
        $count = 0;
        foreach (glob($this->app->storagePath('framework/views/*.php')) ?: [] as $file) {
            if (unlink($file)) $count++;
        }
        $output->info("Removed $count compiled view" . ($count === 1 ? '' : 's') . '.');
        return 0;
    }
}
