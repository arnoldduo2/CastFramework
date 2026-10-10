<?php

declare(strict_types=1);

namespace Cast\Services;

use Anode\ErrorHandler\ErrorHandler;
use Cast\App\Application;
use Cast\App\ServiceProvider;
use Cast\Core\Config;

/**
 * Registers `anode/error-handler` (logging and error pages for uncaught errors) on web requests.
 * Turn it off with `'error_handler' => false` in config/app.php. HTTP errors (404, 403, 419...) are handled by the Kernel.
 */
final class ErrorProvider extends ServiceProvider
{
    public function register(): void
    {
        $setting = Config::get('app.error_handler', true);
        $file = (array) Config::get('error-handler', []);
        // `false` turns it off; `true` uses the defaults; an array is the package's options (`enabled => false`, here or in config/error-handler.php, also turns it off)
        if (PHP_SAPI === 'cli' || $setting === false || (is_array($setting) && ($setting['enabled'] ?? true) === false) || ($file['enabled'] ?? true) === false || !class_exists(ErrorHandler::class)) return;

        $this->app->set('error_handler', new ErrorHandler(self::options($this->app)));
    }

    /**
     * The options passed to the handler. Note the package's own spellings: `log_directory` (singular, with a
     * trailing slash) and `app_enviroment`. Unknown keys are silently ignored by the package.
     * @return array<string, mixed>
     */
    public static function options(Application $app): array
    {
        // options from config/app.php ('error_handler' => [...]) and from config/error-handler.php (the file wins)
        $given = Config::get('app.error_handler', true);
        $given = array_replace(is_array($given) ? $given : [], (array) Config::get('error-handler', []));
        $given = array_diff_key($given, ['enabled' => 1]);
        // folders are written relative to the app
        foreach (['log_directory', 'dev_logs_directory', 'error_view'] as $key) {
            if (isset($given[$key]) && is_string($given[$key]) && $given[$key] !== '' && !preg_match('#^([a-z]:)?[\\/]#i', $given[$key])) {
                $given[$key] = rtrim($app->basePath($given[$key]), '/\\') . (str_ends_with($key, 'directory') ? DIRECTORY_SEPARATOR : '');
            }
        }
        return $given + [
            'app_name' => (string) Config::get('app.name', 'App'),
            'app_debug' => (bool) Config::get('app.debug', false),
            'app_enviroment' => (string) Config::get('app.env', 'production'),
            'base_url' => Config::get('app.base_path', '') . '/',
            'log_directory' => $app->storagePath('logs') . DIRECTORY_SEPARATOR,
            'dev_logs' => false,
            // 1.3 of the handler: short paths, links that open the file in your editor (env CAST_EDITOR: vscode, cursor, phpstorm, none ...)
            'root_path' => $app->basePath(),
            'editor' => (string) (\Cast\Core\Env::get('CAST_EDITOR') ?: 'vscode'),
        ];
    }
}
