<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Support\DebugScanner;

/** `php cast deploy:scan`: lists the debugging left in your code (dd, dump, var_dump, print_r, console.log, debugger ...). console.error() is allowed. */
final class DeployScanCommand extends Command
{
    protected string $name = 'deploy:scan';
    protected string $description = 'Find leftover debugging in your code: dd(), dump(), var_dump(), print_r(), console.log(), debugger';
    protected array $options = [
        '--json' => 'Print the findings as JSON',
    ];
    protected array $examples = [
        'php cast deploy:scan' => 'list every leftover (exit code 1 when there are any)',
        'php cast deploy:scan --json' => 'for scripts and CI',
    ];

    public function handle(Input $input, Output $output): int
    {
        $hits = self::scanApp($this->app);
        if ($input->hasOption('json')) {
            $output->line((string) json_encode($hits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $hits ? 1 : 0;
        }
        if (!$hits) {
            $output->info('No leftover debugging found (console.error() is allowed).');
            return 0;
        }
        foreach ($hits as $hit) $output->line('  ' . $output->color($hit['file'] . ':' . $hit['line'], 'blue') . '  ' . $hit['what']);
        $output->line();
        $output->error(count($hits) . ' leftover(s). Remove them, or keep one on purpose with a  cast:keep  comment on the same line.');
        $output->line('Need debugging in production? deploy:check skips this scan with --allow-debug or DEPLOY_ALLOW_DEBUG=true in .env.');
        return 1;
    }

    /** @return list<array{file: string, line: int, what: string}> */
    public static function scanApp(\Cast\App\Application $app): array
    {
        $source = (string) config('app.source_path', 'app');
        $roots = array_unique(array_filter([
            $app->basePath($source), $app->routesPath(), $app->configPath(), $app->databasePath(), $app->basePath('bootstrap'),
            $app->viewsPath(), $app->resourcesPath(), $app->publicPath(),
        ], fn($p) => is_dir($p)));
        return (new DebugScanner())->scan(array_values($roots), $app->basePath());
    }
}
