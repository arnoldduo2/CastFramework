<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};
use Cast\Core\Config;

/**
 * `php cast make:module Reports`: a module is a controller, its pages and its routes behind a gate. This writes them, lists the module in
 * config/modules.php (core or optional) and leaves the gate itself, a middleware, to the routes file:  Router::module('reports', ...).
 */
final class MakeModuleCommand extends Command
{
    protected string $name = 'make:module';
    protected string $description = 'Create a module: controller, pages, a gated routes file, and its entry in config/modules.php';
    protected array $arguments = ['Name' => 'The module name, e.g. Reports or purchase-orders'];
    protected array $options = [
        '--core' => 'A core module: the app cannot work without it (modules:check fails when it is missing, it cannot be switched off). Asked when left out',
        '--title=TEXT' => 'The name shown on the fallback page (default: the module name in words)',
        '--model' => 'Also create a model class for the module',
        '--no-config' => 'Do not touch config/modules.php',
        '--force' => 'Overwrite files that exist',
    ];
    protected array $examples = [
        'php cast make:module Reports' => 'an optional module with a controller, two views and routes/modules/reports.php',
        'php cast make:module Billing --core --model' => 'a core module with a Billing model too',
    ];

    public function handle(Input $input, Output $output): int
    {
        $given = $input->argument(0);
        if ($given === null || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $given)) {
            $output->error('Usage: php cast make:module <Name>   (letters, numbers, - and _)');
            return 1;
        }
        $slug = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', str_replace('_', '-', $given)));
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
        $title = (string) ($input->option('title') ?: ucwords(str_replace('-', ' ', $slug)));
        $core = $input->hasOption('core') ?: (Prompt::canAsk($input) ? (new Prompt($output, true))->confirm("Is $title a core module (the app cannot work without it)?", false) : false);

        $ns = (string) Config::get('app.namespace', 'App');
        $source = (string) Config::get('app.source_path', 'app');
        $controller = "{$studly}Controller";
        $files = [
            $this->app->basePath("$source/Controllers/$controller.php") => $this->controller($ns, $controller, $slug, $title),
            $this->app->routesPath("modules/$slug.php") => $this->routes($ns, $controller, $slug, $title),
            $this->app->viewsPath("$slug/$slug.cast.php") => "<?php\n/** The $title page view: the layout's top, the page's content, the layout's bottom. */\n__includes('layouts.header', \$data);\n__includes('$slug.partials.' . \$data['pageName'], \$data);\n__includes('layouts.footer', \$data);\n",
            $this->app->viewsPath("$slug/partials/$slug.cast.php") => "<?php\n/** The $title page content (route GET /$slug, $controller::index). */\nextract(\$data);\n?>\n<h1>$title</h1>\n<p>This page belongs to the <code>$slug</code> module. While the module is off it shows \"module inactive\" instead.</p>\n",
        ];
        if ($input->hasOption('model')) {
            $files[$this->app->basePath("$source/Models/$studly.php")] = "<?php\n\ndeclare(strict_types=1);\n\nnamespace $ns\\Models;\n\nuse Cast\\Core\\Model;\n\n/** The $title module's table. Create it with  php cast make:migration create_" . str_replace('-', '_', $slug) . "_table --create=" . str_replace('-', '_', $slug) . " */\nclass $studly extends Model\n{\n}\n";
        }

        foreach ($files as $path => $code) {
            if (is_file($path) && !$input->hasOption('force')) {
                $output->error($this->relative($path) . ' exists (use --force to overwrite). Nothing was written.');
                return 1;
            }
        }
        foreach ($files as $path => $code) {
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
            file_put_contents($path, $code);
            $output->info('Created ' . $this->relative($path));
        }

        if (!$input->hasOption('no-config')) $this->register($slug, $title, $core, "$ns\\Controllers\\$controller", $output);

        $output->line();
        $output->line($output->color('Next:', 'orange'));
        $output->line('  - the gate is a middleware: ' . $output->color("Router::module('$slug', fn() => ...)", 'green') . ' in routes/modules/' . $slug . '.php (a plain Router::middleware([ModuleGate::class, \'' . $slug . '\'], ...) works too)');
        $output->line('  - module gating is off until you turn it on: CAST_MODULES=true in .env (modules.enabled)');
        $output->line("  - hide its menu item while it is off: <?php if (module_active('$slug')) : ?>");
        $output->line('  - php cast modules:list shows every module and its state');
        return 0;
    }

    private function controller(string $ns, string $class, string $slug, string $title): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace $ns\\Controllers;\n\nuse Cast\\Http\\Controller;\nuse Cast\\Http\\Response;\n\n"
            . "/** The $title module's page. Its routes are in routes/modules/$slug.php, behind the module gate. */\nclass $class extends Controller\n{\n"
            . "    /** GET /$slug */\n    public function index(): Response\n    {\n        return \$this->view('$slug.$slug', [\n            'parentName' => '$slug',\n            'pageName' => '$slug',\n"
            . "            'authguard' => 'private',     // 'public' for everyone, 'private' for signed-in users\n            'spa' => true,\n        ]);\n    }\n}\n";
    }

    private function routes(string $ns, string $class, string $slug, string $title): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse $ns\\Controllers\\$class;\nuse Cast\\Core\\Router;\n\n"
            . "/*\n * The $title module's routes, loaded by the framework from routes/modules/. Router::module() puts them behind the module gate:\n"
            . " * with module gating on (CAST_MODULES=true) and the module switched off or not built, these routes answer with the\n"
            . " * \"module inactive or unavailable\" page instead of running. With gating off it is an ordinary group.\n */\n"
            . "Router::module('$slug', function () {\n    Router::get('/$slug', $class::class);\n});\n";
    }

    /** Add the module to config/modules.php under core or optional, creating the file from the framework's default when missing. */
    private function register(string $slug, string $title, bool $core, string $controller, Output $output): void
    {
        $file = $this->app->configPath('modules.php');
        if (!is_file($file)) {
            if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
            copy(dirname(__DIR__, 2) . '/Stubs/config/modules.php', $file);
            $output->info('Created ' . $this->relative($file));
        }
        $text = (string) file_get_contents($file);
        if (preg_match("/^\s*'" . preg_quote($slug, '/') . "'\s*=>|^\s*'" . preg_quote($slug, '/') . "',/m", $text)) {
            $output->line("config/modules.php already lists $slug.");
            return;
        }
        $group = $core ? 'core' : 'optional';
        $entry = "        '$slug' => ['title' => '" . addcslashes($title, "'\\") . "', 'requires' => [\\" . $controller . "::class]],\n";
        $new = preg_replace("/('" . $group . "'\s*=>\s*\[\R)/", '$1' . addcslashes($entry, '\\$'), $text, 1, $count);
        if (!$count) {
            $output->warn("Add this to the '$group' list in config/modules.php:\n" . rtrim($entry));
            return;
        }
        file_put_contents($file, $new);
        $output->info("Listed $slug as a $group module in config/modules.php");
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($this->app->basePath()))), '/');
    }
}
