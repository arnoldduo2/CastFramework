<?php

declare(strict_types=1);

namespace Cast\App;

use Cast\Core\Config;
use Cast\Core\Env;
use Closure;
use RuntimeException;

/**
 * The application: paths, a small service container, and service providers.
 *
 *   $app = new Application(__DIR__);                       // base folder of the app
 *   $app = new Application(__DIR__, ['paths' => ['config' => 'src/config']]);
 */
final class Application
{
    public const VERSION = '0.9.0';

    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $paths = [
        'config' => 'config',
        'routes' => 'routes',
        'views' => 'resources/views',
        'resources' => 'resources',
        'storage' => 'storage',
        'database' => 'database',
        'public' => 'public',
    ];
    /** @var array<string, array{Closure, bool}> */
    private array $bindings = [];
    /** @var array<string, mixed> */
    private array $resolved = [];
    /** @var list<ServiceProvider> */
    private array $providers = [];
    private bool $booted = false;

    /**
     * @param array{paths?: array<string, string>} $options
     */
    public function __construct(private string $basePath, array $options = [])
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->paths = array_replace($this->paths, $options['paths'] ?? []);
        self::$instance = $this;
        $this->set('app', $this);

        Env::reset();
        Env::load($this->basePath('.env'));
        Config::reset();
        Config::defaults(self::defaultConfig($this->paths));
        Config::load($this->configPath());
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function forget(): void
    {
        self::$instance = null;
    }

    // ------------------------------------------------------------------ paths

    public function basePath(string $path = ''): string
    {
        return $this->join($this->basePath, $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['config']), $path);
    }

    public function routesPath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['routes']), $path);
    }

    public function viewsPath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['views']), $path);
    }

    public function resourcesPath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['resources']), $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['storage']), $path);
    }

    /** Migrations and seeders live here (`paths.database`, default `database/`). */
    public function databasePath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['database']), $path);
    }

    public function publicPath(string $path = ''): string
    {
        return $this->join($this->basePath($this->paths['public']), $path);
    }

    /** The framework's own folder (default views live in `Views/`). */
    public function frameworkPath(string $path = ''): string
    {
        return $this->join(dirname(__DIR__), $path);
    }

    private function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '/\\');
    }

    // -------------------------------------------------------------- container

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = [$factory, false];
        unset($this->resolved[$id]);
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = [$factory, true];
        unset($this->resolved[$id]);
    }

    public function set(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        unset($this->bindings[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || array_key_exists($id, $this->resolved);
    }

    public function make(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) return $this->resolved[$id];
        if (!isset($this->bindings[$id])) {
            throw new RuntimeException("Nothing is bound in the container as \"$id\".");
        }
        [$factory, $shared] = $this->bindings[$id];
        $value = $factory($this);
        if ($shared) $this->resolved[$id] = $value;
        return $value;
    }

    // -------------------------------------------------------------- providers

    /** @param class-string<ServiceProvider>|ServiceProvider $provider */
    public function register(string|ServiceProvider $provider): ServiceProvider
    {
        $provider = is_string($provider) ? new $provider($this) : $provider;
        $provider->register();
        $this->providers[] = $provider;
        if ($this->booted) $provider->boot();
        return $provider;
    }

    /** The providers every app gets (error handling, session, maintenance, views, routes), in order. */
    private const CORE_PROVIDERS = [
        \Cast\Services\ErrorProvider::class,
        \Cast\Services\SessionProvider::class,
        \Cast\Services\MaintenanceProvider::class,
        \Cast\Services\ViewProvider::class,
        \Cast\Services\ApiProvider::class,
        \Cast\Services\DatabaseProvider::class,
        \Cast\Services\RouteProvider::class,
    ];

    /** Register the core providers and `config('app.providers')`, then boot them all. */
    public function boot(): void
    {
        if ($this->booted) return;
        $this->registerAppAutoloader();
        $this->loadCustomHelpers();
        foreach ([...self::CORE_PROVIDERS, ...(array) Config::get('app.providers', [])] as $provider) {
            $this->register($provider);
        }
        $this->booted = true;
        foreach ($this->providers as $provider) $provider->boot();
    }

    /** @var array<string, true> namespaces whose folder is already mapped (per process) */
    private static array $mapped = [];

    /**
     * Map the app's namespace to its source folder (`app.namespace` => `app.source_path`, default `App\` => `app/`) and the
     * seeders (`Database\Seeders\` => `database/seeders`) when Composer's autoloader does not know them yet (a new app before
     * `composer dump-autoload`). Composer's own mapping, when present, is tried first.
     */
    private function registerAppAutoloader(): void
    {
        $this->mapNamespace(trim((string) Config::get('app.namespace', 'App'), '\\') . '\\', $this->basePath((string) Config::get('app.source_path', 'app')));
        $this->mapNamespace('Database\\Seeders\\', $this->databasePath('seeders'));
    }

    private function mapNamespace(string $namespace, string $dir): void
    {
        $key = $namespace . '|' . $dir;
        if (isset(self::$mapped[$key])) return;
        self::$mapped[$key] = true;

        spl_autoload_register(static function (string $class) use ($namespace, $dir): void {
            if (!str_starts_with($class, $namespace)) return;
            $file = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($namespace))) . '.php';
            if (is_file($file)) require $file;
        });
    }

    /** Handle the current HTTP request and send the response. */
    public function run(): void
    {
        (new \Cast\Http\Kernel($this))->run();
    }

    /** Load the app's own helper files from `config('helpers.custom')` (a folder, absolute or relative to the base path). */
    private function loadCustomHelpers(): void
    {
        $dir = Config::get('helpers.custom');
        if (!is_string($dir) || $dir === '') return;
        $dir = preg_match('#^([a-z]:)?[\\/]#i', $dir) ? $dir : $this->basePath($dir);
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            require_once $file;
        }
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    // ----------------------------------------------------------------- config

    /** Framework defaults; the app's `config/*.php` files override these. */
    private static function defaultConfig(array $paths): array
    {
        return [
            'app' => [
                'name' => Env::get('APP_NAME', 'Cast App'),
                'env' => Env::get('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                'key' => Env::get('APP_KEY', ''),   // `php cast key:generate`; used by sign(), encrypt()
                'version' => Env::get('APP_VERSION'),
                'base_path' => rtrim((string) Env::get('APP_BASE_PATH', ''), '/'),
                'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
                'namespace' => 'App',
                'source_path' => 'app',
                'auto_update' => false,
                'error_handler' => true,
                'middleware' => [\Cast\Http\Middleware\Maintenance::class],
                'providers' => [],
            ],
            'database' => [
                'driver' => rtrim((string) Env::get('DB_CONN', 'mysql'), ':'),
                'host' => Env::get('DB_HOST', '127.0.0.1'),
                'port' => Env::get('DB_PORT', ''),
                'name' => Env::get('DB_NAME', ''),
                'user' => Env::get('DB_USER', ''),
                'pass' => Env::get('DB_PASS', ''),
                'charset' => 'utf8mb4',
                // a callable returning a PDO: let another ORM / DBAL own the connection (see docs/ORM-ADAPTERS.md)
                'connection' => null,
                'migrations' => ['table' => 'migrations', 'path' => null],
                'seeder' => 'DatabaseSeeder',
            ],
            'request' => ['sanitizer' => null],
            'session' => [
                'name' => Env::get('SESSION_NAME', 'cast_session'),
                'lifetime' => Env::int('COOKIE_LIFE', 0),
                'path' => Env::get('COOKIE_PATH', '/'),
                'domain' => Env::get('COOKIE_DOMAIN', ''),
                'secure' => Env::bool('COOKIE_SECURE', false),
                'httponly' => Env::bool('COOKIE_HTTP_ONLY', true),
                'samesite' => Env::get('COOKIE_SITE', 'Lax'),
            ],
            'auth' => [
                'session_key' => 'login',
                'login_path' => '/login',
                'home_path' => '/',
                'permissions_key' => 'permissions',
            ],
            'view' => [
                'ext' => Env::get('APP_VIEWS_EXT', '.cast.php'),
                'components' => 'components',
            ],
            'static' => [
                'css' => ['dir' => $paths['resources'], 'keep_prefix' => true],
                'js' => ['dir' => $paths['resources'], 'keep_prefix' => true],
                'styles' => ['dir' => 'public/assets/css', 'keep_prefix' => false],
                'fonts' => ['dir' => 'public/assets/fonts', 'keep_prefix' => false],
                'images' => ['dir' => 'public/assets/images', 'keep_prefix' => false],
                'public' => ['dir' => 'public/assets/vendor', 'keep_prefix' => false],
                'cast' => ['path' => dirname(__DIR__) . '/Resources', 'keep_prefix' => false],   // /cast/cast.module.js, /cast/cast.css
                // the documentation viewer at /cdocs (development only; cdocs.enabled turns it on in production)
                'cdocs' => ['path' => dirname(__DIR__) . '/Resources/docs', 'keep_prefix' => false, 'index' => 'index.html', 'dev_only' => true],
            ],
            'cdocs' => ['enabled' => false],
            'hashing' => ['driver' => Env::get('HASH_DRIVER', 'auto'), 'bcrypt' => ['cost' => 12], 'argon' => ['memory' => 65536, 'time' => 4, 'threads' => 1]],
            'dock' => ['enabled' => Env::bool('CAST_DOCK', true), 'demo_url' => '/demo'],
            'cors' => ['allowed_origins' => array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))))],
            'models' => ['namespace' => 'App\\Models'],
            // `routes/api.php` is loaded under this prefix; `middleware` = specs applied to every API route
            'api' => ['prefix' => '/api', 'middleware' => [], 'tokens' => ['table' => 'api_tokens']],
            'spa' => ['enabled' => true, 'initial' => 'lazy', 'root' => 'body', 'view' => '#cast-view'],
            'helpers' => ['custom' => null],
        ];
    }
}
