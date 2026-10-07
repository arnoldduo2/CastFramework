<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;
use Cast\Core\Env;

final class EnvCheckCommand extends Command
{
    protected string $name = 'env:check';
    protected string $description = 'Check the environment, configuration and folders for common problems';
    protected array $examples = [
        'php cast env:check' => '',
    ];

    public function handle(Input $input, Output $output): int
    {
        $problems = 0;
        $check = function (bool $ok, string $label, string $fix = '') use ($output, &$problems): void {
            if ($ok) {
                $output->info("  ok    $label");
                return;
            }
            $problems++;
            $output->error("  FAIL  $label" . ($fix !== '' ? " ($fix)" : ''));
        };

        $check(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP 8.1 or newer', 'running ' . PHP_VERSION);
        foreach (['pdo', 'mbstring', 'json'] as $ext) $check(extension_loaded($ext), "extension $ext");
        $check(is_file($this->app->basePath('.env')), '.env file exists', 'copy .env.example to .env');
        $check(Env::has('APP_NAME'), 'APP_NAME is set');

        $production = Config::get('app.env') === 'production';
        $check(!($production && Config::get('app.debug')), 'debug is off in production', 'set APP_DEBUG=false');

        $storage = $this->app->storagePath();
        if (!is_dir($storage)) @mkdir($storage, 0775, true);
        $check(is_dir($storage) && is_writable($storage), 'storage folder is writable', $storage);
        $check(is_dir($this->app->viewsPath()), 'views folder exists', $this->app->viewsPath());
        $check(is_dir($this->app->routesPath()), 'routes folder exists', $this->app->routesPath());

        $output->line();
        $problems === 0 ? $output->info('Everything looks good.') : $output->error("$problems problem(s) found.");
        return $problems === 0 ? 0 : 1;
    }
}
