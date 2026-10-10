<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};

/**
 * `php cast demo:strip`: turns an app made with `php cast init --demo` into a clean starter. It deletes the demo's files, rewrites the few
 * files that wired them in (routes, provider, seeder, config) and keeps the structure, the welcome page and the menu. Which parts stay
 * is a "pack": shell (nothing), crud (items, no login), auth (login + an account page), auth-crud (login + items).
 */
final class DemoStripCommand extends Command
{
    protected string $name = 'demo:strip';
    protected string $description = 'Remove the demo and leave a clean starter (shell, crud, auth or auth-crud)';
    protected array $options = [
        '--pack=NAME' => 'clean (a blank page), shell (the welcome page only), crud (items, no login), auth (login, register, an account page) or auth-crud (login + items). Asked when left out',
        '--dry-run' => 'List what would be deleted and rewritten, change nothing',
        '--yes' => 'Do not ask for confirmation (also -n)',
    ];
    protected array $examples = [
        'php cast demo:strip' => 'ask which starter pack to keep',
        'php cast demo:strip --pack=auth-crud --yes' => 'keep login + a table to create, edit and delete; remove the rest of the demo',
        'php cast demo:strip --pack=clean --yes' => 'start from a blank white page with just the framework name and version',
        'php cast demo:strip --pack=shell --dry-run' => 'see what a bare shell would delete',
    ];

    private const PACKS = [
        'clean' => 'a blank white page: one heading with the framework name, version and how the app works. No styles, no components',
        'shell' => 'the welcome page and layout only: no login, no tables',
        'crud' => 'Items (create, edit, delete) for everyone, no login',
        'auth' => 'login, register, logout and a private Account page',
        'auth-crud' => 'login, register and Items (create, edit, delete) for signed-in users',
    ];

    public function handle(Input $input, Output $output): int
    {
        if (!config('app.demo')) {
            $output->error("This app was not made with the demo ('demo' is not true in config/app.php), so there is nothing to strip. Make a clean starter with  php cast init.");
            return 1;
        }
        $ask = new Prompt($output, Prompt::canAsk($input));
        $pack = (string) $input->option('pack');
        if ($pack === '') $pack = $ask->choice('Which starter pack do you want to keep?', self::PACKS, 'auth-crud');
        if (!isset(self::PACKS[$pack])) {
            $output->error('--pack must be one of: ' . implode(', ', array_keys(self::PACKS)));
            return 1;
        }
        $clean = $pack === 'clean';
        $auth = str_starts_with($pack, 'auth');
        $crud = str_ends_with($pack, 'crud');

        $delete = $this->filesToDelete($auth, $crud, $clean);
        $rewrite = $clean ? ['the layout (header, footer), the home page and app.css'] : [];
        $rewrite = [...$rewrite, 'routes/web.php', 'config/app.php', basename((string) config('app.source_path', 'app')) . '/Providers/AppServiceProvider.php', 'database/seeders/DatabaseSeeder.php'];

        $output->line($output->color('Pack: ', 'orange') . $pack . ' - ' . self::PACKS[$pack]);
        $output->line();
        $output->line($output->color('Delete (' . count($delete) . '):', 'orange'));
        foreach ($delete as $path) $output->line('  - ' . $this->relative($path));
        $output->line($output->color('Rewrite:', 'orange'));
        foreach ($rewrite as $path) $output->line('  ~ ' . $path);
        if ($input->hasOption('dry-run')) return 0;

        if (!$input->hasOption('yes') && $ask->interactive() && !$ask->confirm('Delete these files and rewrite the others? Anything you changed in them is lost.', false)) {
            $output->line('Nothing changed.');
            return 0;
        }

        foreach ($delete as $path) $this->remove($path);
        $this->rewriteRoutes($auth, $crud);
        $this->rewriteConfig($auth, $crud);
        $this->rewriteProvider($auth);
        $this->rewriteSeeder($auth);
        if ($auth && !$crud) $this->writeAccountPage();
        if ($crud && !$auth) $this->controllersGuard('private', 'public');
        $this->removeHomeDemoMethod();
        if ($clean) $this->writeCleanSlate();

        $output->line();
        $output->line($output->color("The demo is gone; '$pack' is what is left.", 'green'));
        $output->line('The tables and the data the demo made are still in the database. For a clean one:  ' . $output->color('php cast migrate:fresh' . ($auth ? ' --seed' : ''), 'green'));
        if ($auth) $output->line('Log in with admin@example.com / password (database/seeders/UserSeeder.php); change it before you share the app.');
        $output->line('The menu is  config/app.php  \'menu\'; routes are in routes/web.php (php cast route:list).');
        return 0;
    }

    // --- what goes -----------------------------------------------------------------------------------------------------------------

    /** @return list<string> absolute paths (files or folders) */
    private function filesToDelete(bool $auth, bool $crud, bool $clean = false): array
    {
        $src = $this->app->basePath((string) config('app.source_path', 'app'));
        $views = $this->app->viewsPath();
        $css = $this->app->resourcesPath('css');
        $js = $this->app->resourcesPath('js');
        $db = $this->app->databasePath();
        $paths = [
            "$src/Controllers/StatsController.php", "$src/Controllers/Api", $this->app->routesPath('api.php'),
            "$views/stats", "$views/demo", "$css/stats", "$js/stats",
        ];
        foreach (glob("$db/migrations/*_create_api_tokens_table.php") ?: [] as $f) $paths[] = $f;
        if (!$crud) {
            array_push($paths, "$src/Controllers/ItemsController.php", "$src/Models/Items.php", "$src/helpers/format.php", "$views/items", "$css/items", "$js/items");
            foreach (glob("$db/migrations/*_create_items_table.php") ?: [] as $f) $paths[] = $f;
        }
        if (!$auth) {
            array_push($paths, "$src/Controllers/AuthController.php", "$src/Services/UserStore.php", "$src/Models/Users.php", "$views/auth", "$views/components/crud-banner.cast.php", "$db/seeders/UserSeeder.php", $this->app->configPath('auth.php'));
            foreach (glob("$db/migrations/*_create_users_table.php") ?: [] as $f) $paths[] = $f;
        }
        if ($clean) {
            array_push($paths, "$views/components", $this->app->resourcesPath('js/app/app.module.js'));
            foreach (glob("$css/home/*") ?: [] as $f) $paths[] = $f;
        }
        return array_values(array_filter($paths, fn($p) => file_exists($p)));
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($this->app->basePath()))), '/');
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') $this->remove("$path/$name");
        }
        @rmdir($path);
    }

    // --- what is rewritten ---------------------------------------------------------------------------------------------------------

    private function write(string $path, string $contents): void
    {
        if (is_file($path) && file_get_contents($path) !== $contents) @copy($path, $path . '.bak');   // your version stays next to it
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $contents);
    }

    private function rewriteRoutes(bool $auth, bool $crud): void
    {
        $ns = (string) config('app.namespace', 'App');
        $uses = ["use $ns\\Controllers\\HomeController;"];
        if ($auth) $uses[] = "use $ns\\Controllers\\AuthController;";
        if ($auth && !$crud) $uses[] = "use $ns\\Controllers\\AccountController;";
        if ($crud) $uses[] = "use $ns\\Controllers\\ItemsController;";
        $uses[] = 'use Cast\\Core\\Router;';
        if ($auth) $uses[] = 'use Cast\\Http\\Middleware\\Authenticate;';
        sort($uses);

        $body = "// Anyone\nRouter::get('/', HomeController::class);\n";
        if ($auth) {
            $body .= "\n// Guests only (signed-in users are sent to auth.home_path in config/auth.php)\n"
                . "Router::middleware([Authenticate::class, 'auth'], function () {\n"
                . "    Router::get('/login', [AuthController::class, 'login']);\n"
                . "    Router::post('/login', [AuthController::class, 'attempt']);\n"
                . "    Router::get('/register', [AuthController::class, 'register']);\n"
                . "    Router::post('/register', [AuthController::class, 'store']);\n});\n";
        }
        $items = static fn(string $in): string => "{$in}Router::group('/items', function () {\n"
            . "{$in}    Router::get('/', ItemsController::class);                       // list + form\n"
            . "{$in}    Router::get('/{id}/edit', [ItemsController::class, 'edit']);\n"
            . "{$in}    Router::post('/', [ItemsController::class, 'store']);\n"
            . "{$in}    Router::put('/{id}', [ItemsController::class, 'update']);\n"
            . "{$in}    Router::delete('/{id}', [ItemsController::class, 'destroy']);\n{$in}});\n";
        if ($auth) {
            $body .= "\n// Signed-in users only\nRouter::middleware([Authenticate::class, 'private'], function () {\n    Router::post('/logout', [AuthController::class, 'logout']);\n";
            $body .= $crud ? "\n" . $items('    ') : "    Router::get('/account', AccountController::class);\n";
            $body .= "});\n";
        } elseif ($crud) {
            $body .= "\n// Items: open to everyone. Wrap it in Router::middleware([Authenticate::class, 'private'], ...) when you add a login.\n" . $items('');
        }
        $body .= "\n// Routes that fail, to see the error pages: /_boom (an HTTP error) and /_crash (an exception: in development the code, the stack and an Open in editor link)\nRouter::get('/_boom', fn() => abort(500, 'This is what a server error looks like.'));\nRouter::get('/_crash', function () {\n    \$items = ['qty' => 2];\n    return \$items['qty'] * \$price;      // \$price was never defined\n});\n";

        $this->write($this->app->routesPath('web.php'), "<?php\n\ndeclare(strict_types=1);\n\n" . implode("\n", $uses) . "\n\n"
            . "/*\n * Your app's pages. `Router::get(path, handler)`: the handler is a controller class (its index() runs) or [Controller::class, 'method'].\n"
            . " * Middleware wraps routes in a rule. List every route with  php cast route:list\n */\n\n" . $body);
    }

    private function rewriteConfig(bool $auth, bool $crud): void
    {
        $file = $this->app->configPath('app.php');
        $text = (string) file_get_contents($file);
        $menu = [];
        if ($crud) $menu[] = "['Items', '/items', " . ($auth ? 'true' : 'false') . ']';
        if ($auth && !$crud) $menu[] = "['Account', '/account', true]";
        $lines = "    'auth' => " . ($auth ? 'true' : 'false') . ",                       // the menu shows Log in / Register / Logout\n"
            . "    'showcase' => false,                // hides the \"Demo the Cast Framework\" links\n"
            . "    'menu' => [" . implode(', ', $menu) . "],   // [label, path, signed-in only] shown after Home\n";
        $new = preg_replace('/^\s*\'demo\'\s*=>.*\R/m', $lines, $text, 1, $count);
        $this->write($file, $count ? (string) $new : $text);

        if ($auth) {
            $authFile = $this->app->configPath('auth.php');
            $home = $crud ? '/items' : '/account';
            if (is_file($authFile)) $this->write($authFile, (string) preg_replace("/'home_path'\s*=>\s*'[^']*'/", "'home_path' => '$home'", (string) file_get_contents($authFile)));
        }
    }

    private function rewriteProvider(bool $auth): void
    {
        $ns = (string) config('app.namespace', 'App');
        $file = $this->app->basePath((string) config('app.source_path', 'app')) . '/Providers/AppServiceProvider.php';
        $bind = $auth ? "\n    /** Bind the Auth service. It asks UserStore (Services/UserStore.php) where users live and how to find them. */\n    public function register(): void\n    {\n        \$this->app->singleton('auth', fn() => new Auth(new UserStore()));\n    }\n" : '';
        $uses = ($auth ? "use $ns\\Services\\UserStore;\n" : '') . "use Cast\\App\\ServiceProvider;\n" . ($auth ? "use Cast\\Services\\Auth;\n" : '');
        $this->write($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace $ns\\Providers;\n\n$uses\n"
            . "/**\n * A service provider wires the app's services into the framework. It is listed in config/app.php ('providers').\n"
            . " *   register()  runs first, for every provider: bind things into the container (`\$this->app->singleton('name', fn() => ...)`).\n"
            . " *   boot()      runs when every provider has registered: use services, share data with views, prepare things.\n"
            . " * Get a bound service anywhere with  app('name')  or  \$this->app->make('view').\n */\n"
            . "final class AppServiceProvider extends ServiceProvider\n{" . $bind
            . "\n    /** Prepare the SQLite file, and give every view the app name as \$appName. */\n    public function boot(): void\n    {\n"
            . "        \$this->prepareDatabase();\n        \$this->app->make('view')->share('appName', (string) config('app.name'));\n    }\n\n"
            . "    /** A relative SQLite file is relative to the app folder, and its folder must exist. Tables come from `php cast migrate`. */\n"
            . "    private function prepareDatabase(): void\n    {\n        \$name = (string) config('database.name');\n"
            . "        if (\$name === ':memory:' || config('database.driver') !== 'sqlite') return;\n\n"
            . "        if (!str_starts_with(\$name, '/') && !preg_match('#^[a-z]:[\\\\/]#i', \$name)) {\n            config(['database.name' => \$this->app->basePath(\$name)]);\n        }\n"
            . "        \$dir = dirname((string) config('database.name'));\n        if (!is_dir(\$dir)) @mkdir(\$dir, 0775, true);\n    }\n}\n");
    }

    private function rewriteSeeder(bool $auth): void
    {
        $this->write($this->app->databasePath('seeders/DatabaseSeeder.php'), "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Seeders;\n\nuse Cast\\Database\\Seeder;\n\n"
            . "/** The seeder `php cast migrate --seed` and `php cast db:seed` run: it calls the others. Create one with  php cast make:seeder Name */\n"
            . "class DatabaseSeeder extends Seeder\n{\n    public function run(): void\n    {\n" . ($auth ? "        \$this->call(UserSeeder::class);\n" : "        // \$this->call(YourSeeder::class);\n") . "    }\n}\n");
    }

    /** The `clean` pack: a layout with nothing in it, a home page with one heading, no styles. */
    private function writeCleanSlate(): void
    {
        $views = $this->relative($this->app->viewsPath());
        $routes = 'routes/web.php';
        $controller = $this->relative($this->app->basePath((string) config('app.source_path', 'app'))) . '/Controllers/HomeController.php';
        $this->write($this->app->viewsPath('layouts/header.cast.php'), <<<'PHP'
<?php
/**
 * The top of every page: the <head> and the opening <body>. Each page view starts with  __includes('layouts.header', $data)  and ends with
 * __includes('layouts.footer', $data). Put your menu after <body>. __cast() is the SPA client (remove the line to use normal page loads),
 * __modules() loads the css and js named after the page (resources/css/<parent>/<page>.css), and the csrf-token meta is used by forms and requests.
 */
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htchars(\Cast\Core\Session::csrfToken()) ?>">
    <meta name="base-url" content="<?= htchars(route('/')) ?>">
    <title><?= htchars((string) config('app.name', 'App')) ?></title>
    <?= __cast($data['authguard'] ?? '') ?>
    <?= __modules('app', 'css') ?>
    <?= __modules("$parentName.$pageName", 'css') ?>
</head>

<body>
    <?= __getAlerts() ?>
    <main class="page <?= htchars($pageName) ?>">
PHP . "
");
        $this->write($this->app->viewsPath('layouts/footer.cast.php'), <<<'PHP'
<?php
/** The bottom of every page: closes what header.cast.php opened, then loads the page's own JavaScript. */
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
?>
    </main>
    <?= __modules('app.app', 'js') ?>
    <?= __modules("$parentName.$pageName", 'js') ?>
</body>

</html>
PHP . "
");
        $this->write($this->app->viewsPath('home/partials/home.cast.php'), <<<PHP
<?php
/** The home page content (route GET /, HomeController::index). Replace everything here with your own page. */
?>
<h1>CastFramework <?= htchars(\Cast\App\Application::VERSION) ?></h1>
<p>How this page is made: the route <code>GET /</code> in <code>$routes</code> calls <code>HomeController::index()</code> (<code>$controller</code>),
which renders the view <code>$views/home/home.cast.php</code>. That view puts the layout (<code>$views/layouts/</code>) around this file
(<code>$views/home/partials/home.cast.php</code>). Add a page: a route, a controller method and a view. <code>php cast list</code> shows the commands.</p>

PHP);
        $this->write($this->app->resourcesPath('css/app.css'), "/* Your styles. Loaded on every page by layouts/header.cast.php: __modules('app', 'css').
   A page's own css goes in css/<parent>/<page>.css and loads by itself. */
");
    }

    /** The `auth` pack needs one private page to land on after login. */
    private function writeAccountPage(): void
    {
        $ns = (string) config('app.namespace', 'App');
        $src = $this->app->basePath((string) config('app.source_path', 'app'));
        $this->write("$src/Controllers/AccountController.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace $ns\\Controllers;\n\nuse Cast\\Http\\Controller;\nuse Cast\\Http\\Response;\n\n"
            . "/** A private page (signed-in users only: see routes/web.php). Copy it for your own pages. */\nclass AccountController extends Controller\n{\n"
            . "    /** GET /account */\n    public function index(): Response\n    {\n        return \$this->view('account.account', [\n            'parentName' => 'account',\n            'pageName' => 'account',\n"
            . "            'authguard' => 'private',     // signed-in users only\n            'spa' => true,                // swapped in by the Cast client without a reload\n            'user' => __getUser(),\n        ]);\n    }\n}\n");
        $views = $this->app->viewsPath();
        $this->write("$views/account/account.cast.php", "<?php\n/** The account page view: the layout's top, the page content, the layout's bottom. */\n__includes('layouts.header', \$data);\n__includes('account.partials.' . \$data['pageName'], \$data);\n__includes('layouts.footer', \$data);\n");
        $this->write("$views/account/partials/account.cast.php", "<?php\n/** The account page content (route GET /account, AccountController::index): \$user is the signed-in user. */\nextract(\$data);\n?>\n<Card title=\"Your account\">\n    <p>Signed in as <strong><?= htchars(\$user['email'] ?? '') ?></strong>.</p>\n    <p class=\"muted\">This page is only for signed-in users. Build your app from here.</p>\n</Card>\n");
    }

    /** Items open to everyone (the `crud` pack): the controller's pages are 'private' in the demo. */
    private function controllersGuard(string $from, string $to): void
    {
        $file = $this->app->basePath((string) config('app.source_path', 'app')) . '/Controllers/ItemsController.php';
        if (is_file($file)) file_put_contents($file, str_replace("'authguard' => '$from'", "'authguard' => '$to'", (string) file_get_contents($file)));
    }

    /** `HomeController::demo()` rendered the deleted demo view. */
    private function removeHomeDemoMethod(): void
    {
        $file = $this->app->basePath((string) config('app.source_path', 'app')) . '/Controllers/HomeController.php';
        if (!is_file($file)) return;
        $text = (string) file_get_contents($file);
        $new = preg_replace('~\n    /\*\* GET /demo[^\n]*\*/\n    public function demo\(\): Response\n    \{\n[^\n]*\n    \}\n~', "\n", $text, 1);
        if ($new !== null && $new !== $text) file_put_contents($file, $new);
    }
}
