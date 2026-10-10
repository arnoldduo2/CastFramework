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
        // advice that does not fail the check (except the key in production)
        $advise = function (bool $ok, string $label, string $fix) use ($output): void {
            $ok ? $output->info("  ok    $label") : $output->warn("  note  $label ($fix)");
        };
        $hasKey = (string) Config::get('app.key', '') !== '';
        Config::get('app.env') === 'production'
            ? $check($hasKey, 'APP_KEY is set (sign(), encrypt())', 'php cast key:generate')
            : $advise($hasKey, 'APP_KEY is set (sign(), encrypt())', 'php cast key:generate');

        // the two packages the framework is built on: older versions have known problems
        foreach (['anode/cast-template-engine' => ['1.0.3', 'errors in views name the compiled cache file, not your view'], 'anode/error-handler' => ['1.3.0', 'the error page shows the failing code with an open-in-editor link and the log is readable only from 1.3']] as $package => [$minimum, $why]) {
            if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled($package)) continue;
            $have = ltrim((string) \Composer\InstalledVersions::getPrettyVersion($package), 'v');
            $advise(version_compare($have, $minimum, '>='), "$package $have is current (>= $minimum)", "composer update $package" . ($why !== '' ? "; older: $why" : ''));
        }

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
