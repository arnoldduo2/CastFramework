<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

final class ServeCommand extends Command
{
    protected string $name = 'serve';
    protected string $description = 'Start the PHP development server (--host=127.0.0.1 --port=8000 --dry)';

    public function handle(Input $input, Output $output): int
    {
        $host = (string) $input->option('host', '127.0.0.1');
        $port = (string) $input->option('port', '8000');
        $public = $this->app->publicPath();
        $command = sprintf('%s -S %s:%s -t %s %s', escapeshellarg(PHP_BINARY), $host, $port, escapeshellarg($public), escapeshellarg($public . DIRECTORY_SEPARATOR . 'index.php'));

        if ($input->hasOption('dry')) {
            $output->line($command);
            return 0;
        }
        $output->info("Serving on http://$host:$port (Ctrl+C to stop)");
        passthru($command, $code);
        return $code;
    }
}
