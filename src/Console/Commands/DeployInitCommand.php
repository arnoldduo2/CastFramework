<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};
use Cast\Support\Crypt;

/**
 * `php cast deploy:init`: asks the production questions (address, cookie domain, https cookies, database login, allowed front ends) and writes
 * the settings file for the live server. It starts from your .env (so nothing you set is lost), turns development things off and makes a new APP_KEY.
 */
final class DeployInitCommand extends Command
{
    protected string $name = 'deploy:init';
    protected string $description = 'Ask the production questions (cookie domain, https, database ...) and write the live settings file';
    protected array $options = [
        '--file=PATH' => 'Where to write the settings (default .env.production). Copy it to .env on the server',
        '--url=URL' => 'The public address, e.g. https://app.example.com (an https address turns secure cookies on)',
        '--cookie-domain=HOST' => 'Cookie domain, e.g. .example.com to share it with sub-domains (empty: this host only)',
        '--same-site=VALUE' => 'Lax (default), Strict or None (None needs https)',
        '--db-conn=DRIVER' => 'mysql, pgsql or sqlite',
        '--db-host=HOST' => 'Database host',
        '--db-port=PORT' => 'Database port',
        '--db-name=NAME' => 'Database name (the file for sqlite)',
        '--db-user=USER' => 'Database user',
        '--db-pass=PASSWORD' => 'Database password (asked, without it being shown in your shell history, when left out)',
        '--cors=ORIGINS' => 'Front ends allowed to call the API, comma separated exact origins',
        '--allow-debug' => 'You will debug in production: writes DEPLOY_ALLOW_DEBUG=true so deploy:check does not fail on dd(), console.log() ...',
        '--keep-key' => 'Keep the APP_KEY of your .env instead of making a new one for production',
        '--force' => 'Overwrite the settings file if it exists',
    ];
    protected array $examples = [
        'php cast deploy:init' => 'answer the questions',
        'php cast deploy:init --url=https://app.example.com --db-conn=mysql --db-name=shop --db-user=shop --force' => 'answer some of them on the command line',
    ];

    public function handle(Input $input, Output $output): int
    {
        $ask = new Prompt($output, Prompt::canAsk($input));
        $target = $this->app->basePath((string) ($input->option('file') ?: '.env.production'));
        if (is_file($target) && !$input->hasOption('force')) {
            $output->error($this->relative($target) . ' exists. Use --force to replace it, or --file= for another name.');
            return 1;
        }
        $base = $this->app->basePath('.env');
        $text = is_file($base) ? (string) file_get_contents($base) : '';

        $opt = fn(string $name, string $question, string $default = '') => $input->option($name) !== null && $input->option($name) !== true
            ? (string) $input->option($name) : $ask->ask($question, $default);

        $output->line($output->color('Production settings', 'title'));
        $url = rtrim($opt('url', 'Public address of the app (https://...)', 'https://example.com'), '/');
        $parts = parse_url($url) ?: [];
        $https = ($parts['scheme'] ?? '') === 'https';
        $host = (string) ($parts['host'] ?? '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $cookieDomain = $opt('cookie-domain', 'Cookie domain (empty = ' . ($host ?: 'this host') . ' only; .example.com shares it with sub-domains)', '');
        $site = $opt('same-site', 'SameSite for the session cookie (Lax, Strict, None)', 'Lax');
        $site = ucfirst(strtolower($site));
        if (!in_array($site, ['Lax', 'Strict', 'None'], true)) {
            $output->error('--same-site must be Lax, Strict or None.');
            return 1;
        }
        if ($site === 'None' && !$https) {
            $output->error('SameSite=None only works over https: the browser drops the cookie otherwise.');
            return 1;
        }
        $secure = $https ? true : $ask->confirm('The address is not https. Send the cookie over https only anyway (COOKIE_SECURE)?', false);
        if (!$https) $output->warn('Without https the session cookie and every password travel in the clear. Put a certificate on the server (Let\'s Encrypt is free).');

        $conn = strtolower($opt('db-conn', 'Database (mysql, pgsql, sqlite)', 'mysql'));
        if (!in_array($conn, ['mysql', 'pgsql', 'sqlite'], true)) {
            $output->error('--db-conn must be mysql, pgsql or sqlite.');
            return 1;
        }
        $db = ['DB_CONN' => $conn];
        if ($conn === 'sqlite') {
            $db['DB_NAME'] = $opt('db-name', 'SQLite file (outside public/, writable by the web server)', 'storage/database.sqlite');
        } else {
            $db['DB_HOST'] = $opt('db-host', 'Database host', '127.0.0.1');
            $db['DB_PORT'] = $opt('db-port', 'Database port', $conn === 'mysql' ? '3306' : '5432');
            $db['DB_NAME'] = $opt('db-name', 'Database name', '');
            $db['DB_USER'] = $opt('db-user', 'Database user (not root; give it only this database)', '');
            $db['DB_PASS'] = $opt('db-pass', 'Database password', '');
        }
        $cors = $opt('cors', 'Front ends allowed to call the API (comma separated exact origins, empty for none)', '');
        if (str_contains($cors, '*')) {
            $output->error('CORS_ALLOWED_ORIGINS takes exact origins, not *.');
            return 1;
        }

        $allowDebug = $input->hasOption('allow-debug') || ($ask->interactive() && $ask->confirm('Will you need debugging calls (dd, console.log ...) in production? deploy:check will not fail on them', false));
        $values = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CAST_DOCK' => 'false',
            'APP_BASE_PATH' => $path, 'COOKIE_DOMAIN' => $cookieDomain, 'COOKIE_SECURE' => $secure ? 'true' : 'false',
            'COOKIE_HTTP_ONLY' => 'true', 'COOKIE_SITE' => $site, 'CORS_ALLOWED_ORIGINS' => $cors,
        ] + $db;
        if ($allowDebug) $values['DEPLOY_ALLOW_DEBUG'] = 'true';
        $currentKey = $this->valueOf($text, 'APP_KEY');
        $values['APP_KEY'] = $input->hasOption('keep-key') && $currentKey !== '' ? $currentKey : Crypt::generateKey();
        if ($text === '') $text = "APP_NAME=\"App\"\n";
        foreach ($values as $key => $value) $text = $this->set($text, $key, (string) $value);

        file_put_contents($target, $text);
        @chmod($target, 0600);                     // it holds the database password and the key

        $output->line();
        $output->line($output->color('Wrote ' . $this->relative($target), 'green') . ' (only you can read it). A new APP_KEY was made' . ($input->hasOption('keep-key') ? ' unless you kept yours' : '') . ': signed links and encrypted values made in development will not open in production.');
        $output->line();
        $output->line($output->color('On the server:', 'orange'));
        foreach ([
            'upload the project, but not .env, .git, storage/* or vendor/ (this file becomes .env there)',
            'composer install --no-dev --optimize-autoloader',
            'cp ' . basename($target) . ' .env     # and keep it out of git',
            'point the web server\'s document root at the public/ folder',
            'php cast migrate --force',
            'php cast deploy:check',
        ] as $step) $output->line('  ' . $step);
        return 0;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($this->app->basePath()))), '/');
    }

    private function valueOf(string $text, string $key): string
    {
        return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $text, $m) ? trim($m[1], " \t\"'") : '';
    }

    /** Set KEY=value: replaces the line (also a commented-out one), or adds it. */
    private function set(string $text, string $key, string $value): string
    {
        $quoted = $value !== '' && preg_match('/[\s#"\'$]/', $value) ? '"' . addcslashes($value, '"\\$') . '"' : $value;
        $line = "$key=$quoted";
        $count = 0;
        $new = preg_replace('/^#?\s*' . preg_quote($key, '/') . '=.*$/m', addcslashes($line, '\\$'), $text, 1, $count);
        return $count ? (string) $new : rtrim($text, "\r\n") . "\n$line\n";
    }
}
