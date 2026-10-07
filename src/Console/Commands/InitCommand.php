<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};
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
    protected string $description = 'Create the files of a new app in the current folder';
    protected array $options = [
        '--demo' => 'Copy the full starter app (login, items, API), then migrate and seed it',
        '--no-migrate' => 'With --demo: skip the migrate and seed step',
        '--force' => 'Overwrite files that already exist (.env and AGENTS.md are never overwritten)',
        '--source=DIR' => 'Folder for your app source code (default src)',
        '--frontend=NAME' => 'spa (server pages + the built-in SPA client, default), php (server pages, normal loads), external (API for a React/Vue/Next front end), api (API only)',
        '--cors=ORIGIN' => 'With --frontend=external: the front end\'s address, allowed to call the API (default http://localhost:5173)',
        '--error-handler=yes|no' => 'Use the Anode error handler (default yes; the defaults are listed when you run init interactively)',
        '--error-pages=NAME' => 'default (the framework\'s pages) or custom (empty views in resources/views/errors for you to build)',
        '--no-interaction' => 'Ask nothing: use the defaults and the options above (also the case when input is not a terminal)',
    ];
    protected array $examples = [
        'php cast init' => 'a minimal app',
        'php cast init --demo' => 'the full starter; login admin@example.com / password',
        'php cast init --demo --force --no-migrate' => 'refresh the demo files',
    ];

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

        $answers = $this->answers($input, new Prompt($output, $this->stdin !== null || Prompt::canAsk($input), $this->stdin), $output, $demo);
        if ($answers === null) return 1;
        $src = $answers['source'];

        $name = $this->appName($base);
        $files = array_map(fn($f) => [$source, $f], $this->files($source));
        if ($answers['type'] === 'api') {
            $files = array_merge($files, array_map(fn($f) => [dirname(__DIR__, 2) . '/Stubs/init-api', $f], $this->files(dirname(__DIR__, 2) . '/Stubs/init-api')));
        }

        foreach ($files as [$root, $relative]) {
            if ($demo && $root === $source && $this->skipped($relative)) continue;
            if ($answers['type'] === 'api' && $root === $source && $this->webOnly($relative)) continue;

            $contents = (string) file_get_contents($root . '/' . $relative);
            $target = $demo ? $relative : (str_ends_with($relative, '.stub') ? substr($relative, 0, -5) : $relative);
            if ($target === 'config/app.php') continue;   // written below from the answers
            if ($target === '.env' || ($demo && $target === '.env.example')) {
                $contents = $this->withAppName($contents, $name);
                if ($answers['cors'] !== '') $contents = (string) preg_replace('/^CORS_ALLOWED_ORIGINS=.*$/m', 'CORS_ALLOWED_ORIGINS=' . $answers['cors'], $contents, 1);
            }
            $contents = $this->adapt($target, $contents, $answers);
            // the app's classes live in the chosen source folder (the stubs and the starter keep them under app/)
            if (str_starts_with($target, 'app/')) $target = $src . '/' . substr($target, 4);
            // .env holds the app's settings and secrets: it is created when missing and never overwritten, even with --force
            $this->write($base . '/' . $target, $contents, $force && $target !== '.env', $target);

            if ($demo && $target === '.env.example') {
                $this->write($base . '/.env', $contents, false, '.env');
            }
        }

        $this->write($base . '/config/app.php', $this->configCode($answers, $demo), $force, 'config/app.php');
        if ($answers['errorPages'] === 'custom') {
            // empty on purpose: until you write them the framework's pages are used, with a note in development
            foreach (self::ERROR_PAGES as $code) $this->write($base . "/resources/views/errors/$code.cast.php", '', false, "resources/views/errors/$code.cast.php");
        }

        $this->write($base . '/storage/.gitkeep', '', $force, 'storage/.gitkeep');
        // `php cast <command>`: the launcher is always (re)checked, also for apps made before it existed
        $this->write($base . '/cast', (string) file_get_contents(dirname(__DIR__, 2) . '/Stubs/init/cast.stub'), $force, 'cast');
        @chmod($base . '/cast', 0755);
        // agent hints belong to the app once written: only ever created, never overwritten
        $this->write($base . '/AGENTS.md', str_replace('{source}', $src, (string) file_get_contents(dirname(__DIR__, 2) . '/Stubs/agents.md')), false, 'AGENTS.md');
        $this->ignoreFile($base, $output);
        $autoload = $this->registerAutoload($base, $src);
        // so the editor knows htchars(), views(), ... from the first minute
        (new IdeHelpersCommand($this->app))->handle(new Input(['ide:helpers']), new Output(fopen('php://memory', 'w+')));
        $this->created[] = '_ide_helpers.php';

        foreach ($this->created as $file) $output->info('created  ' . $file);
        foreach ($this->skipped as $file) $output->warn('exists   ' . $file . ($file === '.env' ? ' (kept: init never overwrites .env)' : ' (kept; use --force to overwrite)'));

        $output->line();
        if ($autoload === 'changed') {
            $output->line('Added the "App\\" namespace (' . $src . '/) to composer.json (the app works without it; for production run  composer dump-autoload -o).');
        } elseif ($autoload === 'missing') {
            $output->warn('No composer.json found here. Add  "autoload": {"psr-4": {"App\\\\": "' . $src . '/"}}  to yours, then run composer dump-autoload');
        }
        if ($demo && !$input->hasOption('no-migrate')) $this->migrate($base, $output);

        $this->summary($answers, $output, $demo);
        return 0;
    }

    /** The pages `--error-pages=custom` creates (empty) for you to build. */
    private const ERROR_PAGES = [403, 404, 405, 419, 500, 503];

    /** Standard input for the questions (tests pass a stream). @var resource|null */
    public $stdin = null;

    /**
     * What the user chose, from the options or by asking (a script or `--no-interaction` gets the defaults).
     * @return array{source: string, type: string, frontend: string, cors: string, errorHandler: bool, handlerOptions: array<string, mixed>, errorPages: string}|null
     */
    private function answers(Input $input, Prompt $ask, Output $output, bool $demo): ?array
    {
        if ($ask->interactive()) {
            $output->line('CastFramework setup. Press Enter to accept the [default] of each question.');
            $output->line();
        }

        $source = trim((string) ($input->option('source') ?: $ask->ask('Folder for your app source code (controllers, models, services)', 'src')), '/\\ ');
        if ($source === '' || !preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $source)) {
            $output->error('The source folder must be a simple relative path like src or app/Core (letters, numbers, - _ and /).');
            return null;
        }

        $type = 'web';
        $frontend = 'spa';
        $cors = '';
        if (!$demo) {
            $frontend = $this->pick($input, $ask, $output, 'frontend', 'How will the front end work?', [
                'spa' => 'a web app: pages rendered by the server, with the built-in SPA client (no full reloads)',
                'php' => 'a web app: pages rendered by the server, normal page loads (no client script)',
                'external' => 'an API only: I will use a front-end framework (React, Vue, Next.js...) on another address',
                'api' => 'an API only: JSON for other servers or tools (no front end)',
            ], 'spa');
            if ($frontend === null) return null;
            $type = in_array($frontend, ['external', 'api'], true) ? 'api' : 'web';
            if ($frontend === 'external') {
                $cors = (string) ($input->option('cors') ?: $ask->ask('Address of your front end (allowed to call this API, CORS)', 'http://localhost:5173'));
            }
        }

        $handler = $this->yesNo($input, $ask, 'error-handler', 'Use the Anode error handler (logs errors, shows a developer error page)?', true);
        $options = [];
        if ($handler && $ask->interactive()) {
            $this->showHandlerDefaults($output);
            if ($ask->confirm('Configure the error handler now (otherwise the defaults above are used)?', false)) $options = $this->configureHandler($ask);
        }

        $pages = 'framework';
        if ($type === 'web') {
            $pages = $this->pick($input, $ask, $output, 'error-pages', 'Error pages (404 not found, 403, 500 ...)?', [
                'default' => 'use the framework\'s pages',
                'custom' => 'I will build my own: create empty views in resources/views/errors (until built, the framework\'s page is shown, with a note in development)',
            ], 'default');
            if ($pages === null) return null;
            $pages = $pages === 'custom' ? 'custom' : 'framework';
        }

        return ['source' => $source, 'type' => $type, 'frontend' => $frontend, 'cors' => $cors, 'errorHandler' => $handler, 'handlerOptions' => $options, 'errorPages' => $pages];
    }

    /** @param array<string, string> $choices */
    private function pick(Input $input, Prompt $ask, Output $output, string $option, string $question, array $choices, string $default): ?string
    {
        $given = $input->option($option);
        if (is_string($given) && $given !== '') {
            if (!isset($choices[$given])) {
                $output->error("--$option must be one of: " . implode(', ', array_keys($choices)));
                return null;
            }
            return $given;
        }
        return $ask->choice($question, $choices, $default);
    }

    private function yesNo(Input $input, Prompt $ask, string $option, string $question, bool $default): bool
    {
        $given = $input->option($option);
        if (is_string($given) && $given !== '') return in_array(strtolower($given), ['y', 'yes', 'true', '1', 'on'], true);
        return $ask->confirm($question, $default);
    }

    private function showHandlerDefaults(Output $output): void
    {
        $output->line();
        $output->line('The error handler\'s settings and their defaults:');
        $output->table(['Setting', 'Default', 'Meaning'], [
            ['log_errors', 'true', 'write every error to a log file'],
            ['log_directory', 'storage/logs/', 'where the log files go'],
            ['dev_logs', 'false', 'extra developer logs (more detail) in dev_logs_directory'],
            ['dev_logs_directory', 'storage/logs/dev/', 'where developer logs go'],
            ['display_errors', 'false', 'let PHP print errors into the page (leave off: the handler shows its own page)'],
            ['error_reporting_level', 'E_ALL', 'which PHP errors are reported'],
            ['email_logging', 'false', 'email errors to someone (needs a mailer object)'],
            ['email_logging_address / _subject', "'' / 'Error Log'", 'who gets the emails, and the subject'],
            ['error_view', "the package's page", 'the page visitors see in production'],
            ['app_name, app_enviroment, app_debug, base_url', 'APP_NAME, APP_ENV, APP_DEBUG, APP_BASE_PATH', 'taken from your .env: in development you see the full error, in production a friendly page'],
        ]);
        $output->line('All of these can be changed later in config/app.php under \'error_handler\'.');
        $output->line();
    }

    /** @return array<string, mixed> only what differs from the defaults */
    private function configureHandler(Prompt $ask): array
    {
        $o = [];
        if (!$ask->confirm('Log errors to files?', true)) $o['log_errors'] = false;
        $dir = $ask->ask('Log folder', 'storage/logs');
        if (trim($dir, '/\\') !== 'storage/logs') $o['log_directory'] = trim($dir, '/\\') . '/';
        if ($ask->confirm('Also write developer logs (more detail)?', false)) {
            $o['dev_logs'] = true;
            $devDir = $ask->ask('Developer log folder', 'storage/logs/dev');
            if (trim($devDir, '/\\') !== 'storage/logs/dev') $o['dev_logs_directory'] = trim($devDir, '/\\') . '/';
        }
        if ($ask->confirm('Let PHP print errors into the page too (display_errors)?', false)) $o['display_errors'] = true;
        if ($ask->confirm('Email errors?', false)) {
            $o['email_logging'] = true;
            $o['email_logging_address'] = $ask->ask('Send to which address');
            $o['email_logging_subject'] = $ask->ask('Subject', 'Error Log');
        }
        return $o;
    }

    /** Files only a web app needs. */
    private function webOnly(string $relative): bool
    {
        return str_starts_with($relative, 'resources/') || $relative === 'routes/web.php.stub' || str_contains($relative, 'HomeController');
    }

    /** Small changes to a stub for the chosen front end. @param array<string, mixed> $a */
    private function adapt(string $target, string $contents, array $a): string
    {
        if ($a['frontend'] === 'php') {
            // no client script, and pages are normal page loads
            if (str_ends_with($target, 'Controllers/HomeController.php')) {
                $contents = (string) preg_replace("/\n\s*'spa' => true,[^\n]*/", '', $contents);
            }
            if (str_ends_with($target, 'layouts/header.cast.php')) {
                $contents = (string) preg_replace("/\n\s*<\?= __cast\([^\n]*\n/", "\n", $contents);
            }
        }
        if (str_ends_with($target, 'partials/home.cast.php')) $contents = (string) preg_replace('~\bapp/(Controllers|Models|helpers)~', $a['source'] . '/$1', $contents);
        if ($target === 'config/helpers.php') $contents = str_replace("'app/helpers'", "'" . $a['source'] . "/helpers'", $contents);
        return str_replace('{source}', $a['source'], $contents);
    }

    /** config/app.php: only what differs from the framework's defaults. @param array<string, mixed> $a */
    private function configCode(array $a, bool $demo): string
    {
        $lines = ["    'namespace' => 'App',", "    'source_path' => " . var_export($a['source'], true) . ','];
        if (!$a['errorHandler']) {
            $lines[] = "    'error_handler' => false,   // the Anode error handler is off";
        } elseif ($a['handlerOptions']) {
            $lines[] = "    // Anode error handler options (see the package: log_errors, log_directory, dev_logs, display_errors, email_logging...)";
            $lines[] = "    'error_handler' => [";
            foreach ($a['handlerOptions'] as $k => $v) $lines[] = '        ' . var_export($k, true) . ' => ' . var_export($v, true) . ',';
            $lines[] = '    ],';
        }
        if ($a['errorPages'] === 'custom') {
            $lines[] = "    // 'custom': you build resources/views/errors/{404,403,500,...}.cast.php; an empty one falls back to the framework's page";
            $lines[] = "    'error_pages' => 'custom',";
        }
        $lines[] = "    'providers' => [" . ($demo ? 'App\\Providers\\AppServiceProvider::class' : '') . '],';
        return "<?php\n\n// Only list what differs from the framework defaults (see the README: Configuration).\nreturn [\n" . implode("\n", $lines) . "\n];\n";
    }

    /** @param array<string, mixed> $a */
    private function summary(array $a, Output $output, bool $demo): void
    {
        $output->line('Your app:  source in ' . $a['source'] . '/ (namespace App\\), ' . ($a['type'] === 'api' ? 'an API (routes/api.php under /api)' : ($a['frontend'] === 'php' ? 'server-rendered pages' : 'server-rendered pages with the built-in SPA client')));
        $output->line('Errors:    ' . ($a['errorHandler'] ? 'Anode error handler on (config/app.php, \'error_handler\')' : 'Anode error handler off') . '; ' . ($a['errorPages'] === 'custom' ? 'your own error pages in resources/views/errors (empty until you build them)' : 'framework error pages'));
        if ($a['frontend'] === 'external') $output->line('Front end:  ' . ($a['cors'] ?: 'set CORS_ALLOWED_ORIGINS in .env') . ' may call the API; create a token with  php cast token:create <login>');
        $output->line('VS Code:   php cast editor:install   (highlighting and Ctrl+click for .cast.php views)');
        $output->line('Start it:  php cast serve   (then open http://127.0.0.1:8000). See all commands:  php cast list');
        if ($demo) $output->line('Demo login:  admin@example.com / password');
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
        foreach (['/vendor/', '.env', '/storage/*', '!/storage/.gitkeep', '/_ide_helpers.php'] as $line) {
            if (!preg_match('/^' . preg_quote($line, '/') . '\s*$/m', $have)) $add[] = $line;
        }
        if (!$add) return;

        file_put_contents($path, rtrim($have) . ($have === '' ? '' : "\n") . implode("\n", $add) . "\n");
        $this->created[] = '.gitignore (updated)';
    }

    /** @return string 'changed' | 'present' | 'missing' */
    private function registerAutoload(string $base, string $source = 'src'): string
    {
        $file = $base . '/composer.json';
        if (!is_file($file)) return 'missing';

        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json)) return 'missing';

        $psr4 = $json['autoload']['psr-4'] ?? [];
        foreach ($psr4 as $namespace => $dir) {
            if ($namespace === 'App\\') return 'present';
        }
        $json['autoload']['psr-4']['App\\'] = $source . '/';
        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        return 'changed';
    }
}
