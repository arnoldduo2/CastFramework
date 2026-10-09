<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

/**
 * `php cast make:config <name>` writes `config/<name>.php` with every setting of that section and its default, documented, ready to edit.
 * The framework only needs the values you change, so a config file is optional until you want one.
 */
final class MakeConfigCommand extends Command
{
    protected string $name = 'make:config';
    protected string $description = 'Create a config file with all of a section\'s settings and their defaults';
    protected array $arguments = [
        'name?' => 'Which one: app, database, session, cors, cdocs, static, view, api, spa, auth, models, helpers, request, console, error-handler (or --all, --list)',
    ];
    protected array $options = [
        '--list' => 'Show the sections you can create',
        '--all' => 'Create every config file that does not exist yet',
        '--force' => 'Overwrite a config file that exists',
    ];
    protected array $examples = [
        'php cast make:config session' => 'config/session.php: cookie name, lifetime, secure, samesite',
        'php cast make:config error-handler' => 'config/error-handler.php: logging, developer logs, email',
        'php cast make:config static' => 'config/static.php: where css, js, fonts and images are served from',
        'php cast make:config --list' => '',
    ];

    /** section => what it is for */
    private const SECTIONS = [
        'app' => 'name, environment, debug, key, timezone, source folder, middleware, providers',
        'database' => 'connection, migrations table, seeder',
        'session' => 'the session cookie (name, lifetime, secure, httponly, samesite)',
        'cors' => 'origins allowed to call the app from another site',
        'dock' => 'the floating dock with links to the demo and the docs',
        'cdocs' => 'the framework documentation viewer at /cdocs',
        'static' => 'where css, js, fonts, images and the SPA client are served from',
        'view' => 'view extension and components folder',
        'api' => 'the JSON API prefix, middleware and token table',
        'spa' => 'the built-in SPA client',
        'auth' => 'login session key and paths',
        'models' => 'where models are looked up',
        'helpers' => 'your own helper functions folder',
        'request' => 'input sanitizer',
        'console' => 'your own console commands',
        'error-handler' => 'the Anode error handler: logging, developer logs, email, error view',
    ];

    public function handle(Input $input, Output $output): int
    {
        if ($input->hasOption('list')) {
            $output->table(['Name', 'What it sets'], array_map(fn($n, $d) => [$n, $d], array_keys(self::SECTIONS), self::SECTIONS));
            return 0;
        }
        $names = $input->hasOption('all') ? array_keys(self::SECTIONS) : array_filter([$input->argument(0)]);
        if (!$names) {
            $output->error('Which config? One of: ' . implode(', ', array_keys(self::SECTIONS)) . '  (php cast make:config --list)');
            return 1;
        }

        $made = 0;
        foreach ($names as $name) {
            $name = strtolower(str_replace('_', '-', $name));
            if (!isset(self::SECTIONS[$name])) {
                $output->error("There is no config section \"$name\". One of: " . implode(', ', array_keys(self::SECTIONS)));
                return 1;
            }
            $file = $this->app->configPath("$name.php");
            if (is_file($file) && !$input->hasOption('force')) {
                $output->warn("config/$name.php already exists (kept; use --force to overwrite)");
                continue;
            }
            $code = strtr((string) file_get_contents(dirname(__DIR__, 2) . "/Stubs/config/$name.php"), [
                '{resources}' => $this->relative($this->app->resourcesPath()),
            ]);
            if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
            file_put_contents($file, $code);
            $output->info('Created ' . $this->relative($file));
            $made++;
        }
        return 0;
    }

    /** relative to the app when it is inside it */
    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', $this->app->basePath()) . '/';
        $p = str_replace('\\', '/', $path);
        return str_starts_with($p, $base) ? substr($p, strlen($base)) : $p;
    }
}
