<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

final class ServeCommand extends Command
{
    protected string $name = 'serve';
    protected string $description = 'Start the PHP development server';
    protected array $options = [
        '--host=ADDRESS' => 'Address to listen on (default 127.0.0.1)',
        '--port=N' => 'Port (default 8000)',
        '--dry' => 'Only print the command that would run',
    ];
    protected array $examples = [
        'php cast serve' => 'http://127.0.0.1:8000',
        'php cast serve --port=9000' => 'another port',
        'php cast serve --host=0.0.0.0' => 'reachable from the network',
    ];

    public function handle(Input $input, Output $output): int
    {
        $host = (string) $input->option('host', '127.0.0.1');
        $port = (string) $input->option('port', '8000');
        $public = $this->app->publicPath();
        $router = $public . DIRECTORY_SEPARATOR . 'index.php';
        // The router script (the last argument) sends every request to the framework, so css/js/cast/cdocs files are served.
        $argv = [PHP_BINARY, '-S', "$host:$port", '-t', $public, $router];

        if ($input->hasOption('dry')) {
            $output->line(implode(' ', array_map(static fn(string $a): string => preg_match('/[\s"\']/', $a) ? '"' . $a . '"' : $a, $argv)));
            return 0;
        }
        if (!is_file($router)) {
            $output->error("Router script not found: $router (run this from the project folder)");
            return 1;
        }
        $output->info("Serving on http://$host:$port (Ctrl+C to stop)");
        $output->line("Router script: $router");

        // An argument array is started without a shell, so Windows (cmd.exe, Git Bash) cannot mangle the quotes and drop the router.
        $process = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            $output->error('Could not start the PHP server.');
            return 1;
        }
        return proc_close($process);
    }
}
