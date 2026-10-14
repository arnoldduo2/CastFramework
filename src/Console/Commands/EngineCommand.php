<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\App\Application;
use Cast\Console\{Command, Input, Output};

/**
 * `engine:build`, `engine:check`, `engine:compile`, `engine:clear`, `engine:snippets`, `engine:editor`:
 * CastTemplateEngine integration commands for CastFramework.
 */
final class EngineCommand extends Command
{
    public function __construct(Application $app, private string $action = 'build')
    {
        parent::__construct($app);
        $this->name = "engine:{$action}";
        [$this->description, $this->arguments, $this->options, $this->examples] = match ($action) {
            'build' => [
                'Minify and bundle component CSS and JS into resources/css/app.css and resources/js/app.js',
                ['path' => 'Project root path (defaults to app root)'],
                [],
                ['php cast engine:build' => 'bundle all component assets for production']
            ],
            'check' => [
                'Lint all .cast.php files for syntax errors and missing keys',
                ['path' => 'Directory to scan (defaults to views folder)'],
                [],
                ['php cast engine:check' => 'scan views and components']
            ],
            'compile' => [
                'Precompile all .cast.php templates into the cache folder',
                ['path' => 'Views directory to compile'],
                [],
                ['php cast engine:compile' => 'warm up the compiled views cache']
            ],
            'clear' => [
                'Clear the compiled template cache',
                [],
                [],
                ['php cast engine:clear' => 'wipe compiled template files']
            ],
            'snippets' => [
                'List all available template code snippets and shorthand triggers',
                [],
                [],
                ['php cast engine:snippets' => 'display snippets reference table']
            ],
            'editor' => [
                'Install or update the Cast VS Code extension',
                ['subcommand' => 'install or update'],
                [],
                ['php cast engine:editor install' => 'install editor support']
            ],
            default => [
                'CastTemplateEngine tool',
                [],
                [],
                []
            ]
        };
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return ['build', 'check', 'compile', 'clear', 'snippets', 'editor'];
    }

    public function handle(Input $input, Output $output): int
    {
        $this->ensureEngineAutoloaded();

        if (class_exists(\CastTemplateEngine\Console\ConsoleApplication::class)) {
            \CastTemplateEngine\Console\ConsoleApplication::init();
            $cmd = \CastTemplateEngine\Console\CommandRegistry::get("engine:{$this->action}");
            if ($cmd !== null) {
                $args = $input->arguments();
                // If path argument is not provided for build or check, default to framework base or views path
                if (empty($args)) {
                    if ($this->action === 'build') {
                        $args = [$this->app->basePath()];
                    } elseif ($this->action === 'check') {
                        $args = [$this->app->viewsPath()];
                    }
                }
                return $cmd instanceof \CastTemplateEngine\Console\CommandInterface
                    ? $cmd->execute($args)
                    : $cmd($args);
            }
        }

        $output->error("CastTemplateEngine command engine:{$this->action} could not be resolved.");
        return 1;
    }

    private function ensureEngineAutoloaded(): void
    {
        if (class_exists(\CastTemplateEngine\Console\ConsoleApplication::class)) {
            return;
        }

        $candidates = [
            $this->app->basePath('vendor/anode/cast-template-engine/src'),
            dirname($this->app->basePath()) . '/CastTemplateEngine/src',
            dirname(__DIR__, 4) . '/CastTemplateEngine/src',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                spl_autoload_register(function (string $class) use ($dir) {
                    $prefix = 'CastTemplateEngine\\';
                    if (str_starts_with($class, $prefix)) {
                        $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            require_once $file;
                        }
                    }
                });
                if (is_file(dirname($dir) . '/src/helpers.php')) {
                    require_once dirname($dir) . '/src/helpers.php';
                }
                break;
            }
        }
    }
}