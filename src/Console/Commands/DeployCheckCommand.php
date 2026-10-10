<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\{Config, Database};
use Cast\Support\Requirements;

/** `php cast deploy:check`: is this install ready to face the internet? Run it on the live server (or with APP_ENV=production). */
final class DeployCheckCommand extends Command
{
    protected string $name = 'deploy:check';
    protected string $description = 'Check that this install is ready for production (settings, cookies, database, PHP, folders)';
    protected array $options = [
        '--skip-db' => 'Do not try to connect to the database',
        '--allow-debug' => 'Do not fail on leftover debugging (dd, console.log ...): you debug in production on purpose. DEPLOY_ALLOW_DEBUG=true in .env does the same',
    ];
    protected array $examples = [
        'php cast deploy:check' => 'run on the server after deploy:init and migrate',
        'php cast deploy:check --skip-db' => 'when the database is not reachable from where you run it',
    ];

    public function handle(Input $input, Output $output): int
    {
        $fails = 0;
        $warns = 0;
        $report = function (string $status, string $label, string $fix = '') use ($output, &$fails, &$warns): void {
            if ($status === 'ok') {
                $output->info("  ok    $label");
            } elseif ($status === 'warn') {
                $warns++;
                $output->warn("  note  $label" . ($fix !== '' ? " ($fix)" : ''));
            } else {
                $fails++;
                $output->error("  FAIL  $label" . ($fix !== '' ? " ($fix)" : ''));
            }
        };
        $is = fn(bool $ok, string $label, string $fix, bool $hard = true) => $report($ok ? 'ok' : ($hard ? 'fail' : 'warn'), $label, $ok ? '' : $fix);

        $output->line($output->color('Settings', 'orange'));
        $is(Config::get('app.env') === 'production', 'APP_ENV is production', 'set APP_ENV=production in .env');
        $is(!Config::get('app.debug'), 'APP_DEBUG is false (no error details for visitors)', 'APP_DEBUG=false');
        $is((string) Config::get('app.key', '') !== '', 'APP_KEY is set', 'php cast key:generate');
        $is(!Config::get('cdocs.enabled'), 'the framework docs (/cdocs) are off', 'cdocs.enabled => false in config/cdocs.php', false);
        $is(!Config::get('dock.enabled') || Config::get('app.env') === 'production', 'the dock is off', 'CAST_DOCK=false', false);

        $output->line($output->color('Cookies and CORS', 'orange'));
        $secure = (bool) Config::get('session.secure', false);
        $is($secure, 'the session cookie is https-only (COOKIE_SECURE=true)', 'serve the site over https, then COOKIE_SECURE=true');
        $is((bool) Config::get('session.httponly', true), 'the session cookie is HttpOnly', 'COOKIE_HTTP_ONLY=true');
        $site = (string) Config::get('session.samesite', 'Lax');
        $is(strcasecmp($site, 'None') !== 0 || $secure, "SameSite=$site works with these settings", 'SameSite=None needs COOKIE_SECURE=true');
        $origins = (array) Config::get('cors.allowed_origins', []);
        $local = array_filter($origins, fn($o) => preg_match('#//(localhost|127\.0\.0\.1)#', (string) $o));
        $is(!$local && !in_array('*', $origins, true), 'CORS allows no localhost or *', 'remove ' . implode(', ', $local) . ' from CORS_ALLOWED_ORIGINS', true);

        $output->line($output->color('Folders and files', 'orange'));
        $storage = $this->app->storagePath();
        $is(is_dir($storage) && is_writable($storage), 'storage/ is writable', $storage);
        $public = realpath($this->app->publicPath()) ?: '';
        $env = realpath($this->app->basePath('.env')) ?: '';
        $is($env === '' || $public === '' || !str_starts_with($env, $public), '.env is outside the web root (public/)', 'keep .env one level above public/');
        $is($env === '' || (fileperms($env) & 0004) === 0 || DIRECTORY_SEPARATOR === '\\', '.env is not world-readable', 'chmod 600 .env', false);
        $is(!class_exists(\PHPUnit\Framework\TestCase::class, false) && !is_dir($this->app->basePath('vendor/phpunit')), 'development packages are not installed', 'composer install --no-dev --optimize-autoloader', false);
        $is(!is_file($this->app->basePath('.git/HEAD')) || $public === '' || !str_starts_with(realpath($this->app->basePath('.git')) ?: '', $public), '.git is outside the web root', 'document root must be public/');

        $output->line($output->color('Database', 'orange'));
        $driver = (string) Config::get('database.driver', '');
        $is($driver !== 'sqlite', 'a server database (' . ($driver ?: 'none') . ')', 'sqlite is fine for small sites; use mysql or pgsql for many writers', false);
        if (!$input->hasOption('skip-db') && $driver !== '') {
            try {
                Database::connection()->query('SELECT 1');
                $report('ok', 'the database answers');
            } catch (\Throwable $e) {
                $report('fail', 'the database does not answer: ' . $e->getMessage(), 'check DB_* in .env');
            }
        }

        if ($this->app->has('modules') && $this->app->make('modules')->enabled()) {
            $output->line($output->color('Modules', 'orange'));
            foreach ($this->app->make('modules')->all() as $m) {
                $label = ($m['core'] ? 'core' : 'optional') . " module {$m['name']} is {$m['state']}" . ($m['reason'] !== '' ? " ({$m['reason']})" : '');
                $report($m['state'] === 'active' ? 'ok' : ($m['core'] ? 'fail' : 'warn'), $label, $m['state'] === 'active' ? '' : 'php cast modules:list');
            }
        }

        $output->line($output->color('Leftover debugging', 'orange'));
        if ($input->hasOption('allow-debug') || \Cast\Core\Env::bool('DEPLOY_ALLOW_DEBUG', false)) {
            $report('warn', 'debug scan skipped (--allow-debug or DEPLOY_ALLOW_DEBUG=true): dd(), console.log() and friends may run in production');
        } else {
            $hits = DeployScanCommand::scanApp($this->app);
            $report($hits ? 'fail' : 'ok', $hits ? count($hits) . ' leftover debug call(s): ' . implode(', ', array_map(fn($h) => $h['what'] . ' at ' . $h['file'] . ':' . $h['line'], array_slice($hits, 0, 3))) . (count($hits) > 3 ? ' ...' : '') : 'no dd(), dump(), var_dump(), print_r(), console.log() or debugger (console.error() is allowed)', $hits ? 'php cast deploy:scan lists them; cast:keep in a comment keeps one; --allow-debug skips the scan' : '');
        }

        $output->line($output->color('PHP', 'orange'));
        foreach (Requirements::check($driver, true) as [$status, $label, $fix]) $report($status, $label, $fix);

        $output->line();
        if ($fails === 0) {
            $output->info($warns ? "Ready, with $warns note(s) worth a look." : 'Ready for production.');
            return 0;
        }
        $output->error("$fails problem(s) to fix before going live.");
        return 1;
    }
}
