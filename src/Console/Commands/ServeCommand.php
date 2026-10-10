<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};

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

    private static function listening(string $host, int $port): bool
    {
        $probe = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 1);
        if ($probe === false) return false;
        fclose($probe);
        return true;
    }

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
        // On Windows a second server can bind a port that is already taken and then never receives a request,
        // while the old one (maybe started with an older framework) keeps answering. Refuse instead of pretending.
        if (self::listening($host, (int) $port)) {
            $next = (int) $port + 1;
            while ($next < (int) $port + 50 && self::listening($host, $next)) $next++;
            $output->warn("Something is already listening on $host:$port (maybe an old server: on Windows `taskkill /F /IM php.exe` stops it), so a new server there would never get the requests.");
            $ask = new Prompt($output, Prompt::canAsk($input));
            if (!$ask->interactive() || !$ask->confirm("Use port $next instead?", true)) {
                $output->error("Not started. Free the port or run: php cast serve --port=$next");
                return 1;
            }
            $port = (string) $next;
        }
        $output->info("Serving on http://$host:$port (Ctrl+C to stop)");
        $output->line("Router script: $router");

        // An argument array is started without a shell, so Windows (cmd.exe, Git Bash) cannot mangle the quotes and drop the router.
        $process = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            $output->error('Could not start the PHP server.');
            return 1;
        }

        // Stop the server together with this command. On Windows (Git Bash, VS Code) Ctrl+C can end `php cast` and leave
        // the server running in the background, still answering on the port.
        $stop = static function () use ($process): void {
            $status = proc_get_status($process);
            if (!empty($status['running'])) {
                if (PHP_OS_FAMILY === 'Windows') {
                    @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>&1');
                } else {
                    proc_terminate($process);
                }
            }
        };
        register_shutdown_function($stop);
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function () use ($stop): void {
                $stop();
                exit(0);
            });
        } elseif (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, static function () use ($stop): void {
                    $stop();
                    exit(0);
                });
            }
        }

        // poll instead of blocking in proc_close(), so the handlers above can run
        while (($status = proc_get_status($process)) && $status['running']) {
            usleep(200000);
        }
        $code = (int) $status['exitcode'];
        proc_close($process);
        return $code;
    }
}
