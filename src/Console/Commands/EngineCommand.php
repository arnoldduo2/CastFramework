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
                ['--json' => 'Output structured JSON diagnostics for tools'],
                ['php cast engine:check' => 'scan views and components', 'php cast engine:check --json' => 'JSON diagnostics']
            ],
            'lint' => [
                'Authoritative compiler check and linting on view files',
                ['path' => 'File or directory to scan (defaults to views folder)'],
                ['--json' => 'Output machine-readable JSON diagnostics for IDEs and AI tools'],
                ['php cast engine:lint' => 'lint all views', 'php cast engine:lint --json' => 'machine-readable diagnostics']
            ],
            'ast' => [
                'Inspect the AST and metadata (props, components, styles) of a .cast.php template',
                ['file' => 'Path to the .cast.php file to inspect'],
                [],
                ['php cast engine:ast resources/views/app.cast.php' => 'inspect template structure and AST']
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
        return ['build', 'check', 'compile', 'clear', 'snippets', 'editor', 'ast', 'lint'];
    }

    public function handle(Input $input, Output $output): int
    {
        $this->ensureEngineAutoloaded();

        if (class_exists(\CastTemplateEngine\Console\ConsoleApplication::class)) {
            \CastTemplateEngine\Console\ConsoleApplication::init();
            $cmd = \CastTemplateEngine\Console\CommandRegistry::get("engine:{$this->action}");
            if ($cmd !== null) {
                $args = $input->arguments();
                // If path argument is not provided for build or check/lint, default to framework base or views path
                if (empty($args)) {
                    if ($this->action === 'build') {
                        $args = [$this->app->basePath()];
                    } elseif ($this->action === 'check' || $this->action === 'lint') {
                        $args = [$this->app->viewsPath()];
                    }
                }
                // Input splits "--flag"/"--flag=value" into options, but the engine's own CommandInterface::execute()
                // expects them inline in the flat $args array (see EngineEditorCommand::execute()'s own "--editor=" scan).
                foreach ($input->options() as $key => $value) {
                    $args[] = $value === true ? "--$key" : "--$key=$value";
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