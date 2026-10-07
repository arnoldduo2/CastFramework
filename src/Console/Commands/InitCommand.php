<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `php vendor/bin/cast init [--demo] [--force]` (the Composer plugin has normally made `php cast` available already): creates the files of a new app in the current folder
 * (`composer require anode/cast-framework` is the only install step). `--demo` copies the full starter app instead
 * (login, items with an edit modal, stats, and a JSON API).
 */
final class InitCommand extends Command
{
    protected string $name = 'init';
    protected string $description = 'Create the files of a new app here (--demo: the full starter app, migrated and seeded; --force: overwrite)';

    /** Starter paths that are not copied by --demo (they are per install, or built by Composer). */
    private const DEMO_SKIP = ['composer.json', 'composer.lock', 'vendor', '.env', 'storage/database.sqlite'];

    /** @var list<string> */
    private array $created = [];
    /** @var list<string> */
    private array $skipped = [];

    public function handle(Input $input, Output $output): int
    {
        $demo = $input->hasOption('demo');
        $force = $input->hasOption('force');
        $base = $this->app->basePath();

        $source = $demo ? dirname(__DIR__, 3) . '/starter' : dirname(__DIR__, 2) . '/Stubs/init';
        if (!is_dir($source)) {
            $output->error($demo ? 'The starter app is not part of this installation. Reinstall the package, or copy it from the repository.' : 'The app stubs are missing from this installation.');
            return 1;
        }

        $name = $this->appName($base);
        foreach ($this->files($source) as $relative) {
            if ($demo && $this->skipped($relative)) continue;

            $contents = (string) file_get_contents($source . '/' . $relative);
            $target = $demo ? $relative : (str_ends_with($relative, '.stub') ? substr($relative, 0, -5) : $relative);
            if ($target === '.env' || ($demo && $target === '.env.example')) {
                $contents = $this->withAppName($contents, $name);
            }
            // .env holds the app's settings and secrets: it is created when missing and never overwritten, even with --force
            $this->write($base . '/' . $target, $contents, $force && $target !== '.env', $target);

            if ($demo && $target === '.env.example') {
                $this->write($base . '/.env', $contents, false, '.env');
            }
        }

        $this->write($base . '/storage/.gitkeep', '', $force, 'storage/.gitkeep');
        // `php cast <command>`: the launcher is always (re)checked, also for apps made before it existed
        $this->write($base . '/cast', (string) file_get_contents(dirname(__DIR__, 2) . '/Stubs/init/cast.stub'), $force, 'cast');
        @chmod($base . '/cast', 0755);
        $this->ignoreFile($base, $output);
        $autoload = $this->registerAutoload($base);

        foreach ($this->created as $file) $output->info('created  ' . $file);
        foreach ($this->skipped as $file) $output->warn('exists   ' . $file . ($file === '.env' ? ' (kept: init never overwrites .env)' : ' (kept; use --force to overwrite)'));

        $output->line();
        if ($autoload === 'changed') {
            $output->line('Added the "App\\" namespace to composer.json (the app works without it; for production run  composer dump-autoload -o).');
        } elseif ($autoload === 'missing') {
            $output->warn('No composer.json found here. Add  "autoload": {"psr-4": {"App\\\\": "app/"}}  to yours, then run composer dump-autoload');
        }
        if ($demo && !$input->hasOption('no-migrate')) $this->migrate($base, $output);

        $output->line('VS Code:   php cast editor:install   (highlighting and Ctrl+click for .cast.php views)');
        $output->line('Start it:  php cast serve   (then open http://127.0.0.1:8000). See all commands:  php cast list');
        if ($demo) $output->line('Demo login:  admin@example.com / password');
        return 0;
    }

    /** Create the demo's tables and user in a separate process (this one booted before the new .env and config existed). */
    private function migrate(string $base, Output $output): void
    {
        $output->line('Running the migrations and the seeder...');
        exec(sprintf('%s %s migrate --seed 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($base . DIRECTORY_SEPARATOR . 'cast')), $lines, $code);
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'fatal:')) $output->line('  ' . $line);   // (git's own noise from another package is dropped)
        }
        if ($code !== 0) $output->warn('The migrations did not finish. Fix the problem above, then run:  php cast migrate --seed');
        $output->line();
    }

    /** @return list<string> files below $dir, relative, with forward slashes */
    private function files(string $dir): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        }
        sort($found);
        return $found;
    }

    private function skipped(string $relative): bool
    {
        foreach (self::DEMO_SKIP as $skip) {
            if ($relative === $skip || str_starts_with($relative, $skip . '/')) return true;
        }
        return str_starts_with($relative, 'storage/') && $relative !== 'storage/.gitkeep';
    }

    private function write(string $path, string $contents, bool $force, string $label): void
    {
        if (is_file($path) && !$force) {
            if (!in_array($label, $this->skipped, true) && file_get_contents($path) !== $contents) $this->skipped[] = $label;
            return;
        }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, $contents);
        if (!in_array($label, $this->created, true)) $this->created[] = $label;
    }

    private function appName(string $base): string
    {
        $folder = basename($base);
        return ucwords(trim((string) preg_replace('/[-_.\s]+/', ' ', $folder))) ?: 'Cast App';
    }

    private function withAppName(string $env, string $name): string
    {
        $name = str_replace(['"', '\\', "\n", "\r"], '', $name);
        $env = str_replace('{{app_name}}', $name, $env);
        return (string) preg_replace('/^APP_NAME=.*$/m', 'APP_NAME="' . addcslashes($name, '\\$') . '"', $env, 1);
    }

    /** Keep the generated database, secrets and Composer's folder out of git. */
    private function ignoreFile(string $base, Output $output): void
    {
        $path = $base . '/.gitignore';
        $have = is_file($path) ? (string) file_get_contents($path) : '';
        $add = [];
        foreach (['/vendor/', '.env', '/storage/*', '!/storage/.gitkeep'] as $line) {
            if (!preg_match('/^' . preg_quote($line, '/') . '\s*$/m', $have)) $add[] = $line;
        }
        if (!$add) return;

        file_put_contents($path, rtrim($have) . ($have === '' ? '' : "\n") . implode("\n", $add) . "\n");
        $this->created[] = '.gitignore (updated)';
    }

    /** @return string 'changed' | 'present' | 'missing' */
    private function registerAutoload(string $base): string
    {
        $file = $base . '/composer.json';
        if (!is_file($file)) return 'missing';

        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) return 'missing';

        $psr4 = $json['autoload']['psr-4'] ?? [];
        foreach ($psr4 as $namespace => $dir) {
            if ($namespace === 'App\\') return 'present';
        }
        $json['autoload']['psr-4']['App\\'] = 'app/';
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return 'changed';
    }
}
