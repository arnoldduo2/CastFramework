<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Services\StaticResourceProvider;

/**
 * `php cast static:check`: why is a stylesheet, a script or the documentation not showing? Without options it asks the framework in-process
 * (is every static folder there, would each file be served). With `--url=http://localhost/my-app/public/` it also asks your real web server:
 * it loads the page, then every stylesheet and script the page links to, and /cdocs/, and reports the status, type and size of each, with the
 * likely reason for a failure (wrong APP_BASE_PATH, the web server not sending missing files to index.php, a compressed or cut answer).
 */
final class StaticCheckCommand extends Command
{
    protected string $name = 'static:check';
    protected string $description = 'Check that CSS, JS and the documentation are served: in-process, and against your real web server with --url';
    protected array $options = [
        '--url=URL' => 'The address your app is open at (e.g. http://localhost/my-app/public/): every CSS and JS file the page links to is requested there',
        '--timeout=SECONDS' => 'How long to wait for the web server (default 10)',
    ];
    protected array $examples = [
        'php cast static:check' => 'the framework\'s own view: folders, mappings, would each file be served',
        'php cast static:check --url=http://localhost/my-app/public/' => 'also the real requests, for Apache/XAMPP, nginx or php cast serve',
        'php cast static:check --url=http://127.0.0.1:8000' => 'against php cast serve',
    ];

    private int $fail = 0;

    public function handle(Input $input, Output $output): int
    {
        $this->localCheck($output);
        $url = (string) $input->option('url');
        if ($url !== '') $this->liveCheck($url, max(1, (int) ($input->option('timeout') ?: 10)), $output);
        else {
            $output->line();
            $output->line('To test your web server too:  ' . $output->color('php cast static:check --url=http://localhost/your-app/public/', 'green'));
        }
        $output->line();
        $this->fail === 0 ? $output->info('Static files and the documentation are served.') : $output->error($this->fail . ' problem(s). The notes above say what to change.');
        return $this->fail === 0 ? 0 : 1;
    }

    // ---------------------------------------------------------------------------------------------------- in-process

    private function localCheck(Output $output): void
    {
        $base = (string) Config::get('app.base_path', '');
        $output->line($output->color('The app', 'orange'));
        $output->line('  environment   ' . Config::get('app.env') . (Config::get('app.env') === 'production' ? '   (the documentation at /cdocs is off in production unless cdocs.enabled is true)' : ''));
        $output->line('  APP_BASE_PATH ' . ($base === '' ? "(empty: the app is at the web root, e.g. http://localhost:8000/)" : $base . '   (the folder in the address that comes before your routes)'));
        $public = $this->app->publicPath();
        $this->row($output, is_file($public . '/index.php'), 'public/index.php exists');
        $this->row($output, is_file($public . '/.htaccess'), 'public/.htaccess exists (Apache sends files that do not exist to index.php; nginx and php cast serve do not need it)', true);

        $output->line();
        $output->line($output->color('Static folders', 'orange'));
        foreach ((array) Config::get('static', []) as $prefix => $options) {
            $options = is_string($options) ? ['dir' => $options] : (array) $options;
            $dir = isset($options['path']) ? (string) $options['path'] : $this->app->basePath((string) ($options['dir'] ?? ''));
            $real = realpath($dir);
            $label = str_pad('/' . trim((string) $prefix, '/') . '/', 12);
            if ($real === false) {
                // folders such as public/assets/* are optional: only complain about the ones a default page needs
                if (in_array(trim((string) $prefix, '/'), ['css', 'js', 'cast', 'cdocs'], true)) $this->row($output, false, "$label " . $this->short($dir) . ' does not exist');
                else $output->line("  --    $label " . $this->short($dir) . ' (not created; optional)');
                continue;
            }
            $count = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)) as $f) $count += $f->isFile() ? 1 : 0;
            $this->row($output, $count > 0 || !in_array(trim((string) $prefix, '/'), ['cast', 'cdocs'], true), "$label " . $this->short($real) . " ($count files)");
        }

        $output->line();
        $output->line($output->color('Would the framework serve these (in-process)?', 'orange'));
        $provider = new StaticResourceProvider($this->app);
        foreach ($this->probes() as $path) {
            $uri = $base . $path;
            $response = $provider->serve(new Request('GET', $uri));
            if ($response === null) {
                $this->row($output, false, "$path: not served (no file found for it)");
                continue;
            }
            $size = $response->getHeader('Content-Length');
            $this->row($output, $response->statusCode() === 200 || $response->statusCode() === 302, "$path: " . $response->statusCode() . ' ' . ($response->getHeader('Content-Type') ?? '') . ($size !== null ? ", $size bytes" : ''));
        }
        $docs = (string) Config::get('static.cdocs.path', '');
        if ($docs !== '' && is_file("$docs/data.js")) {
            $size = (int) filesize("$docs/data.js");
            $this->row($output, $size > 20000, 'the documentation data (data.js) is ' . number_format($size) . ' bytes' . ($size > 20000 ? '' : ': it should be about 200 KB; reinstall the framework: composer reinstall anode/cast-framework'));
        }
    }

    /** @return list<string> */
    private function probes(): array
    {
        $paths = ['/cast/cast.module.js', '/cast/cast.css', '/cdocs/', '/cdocs/data.js'];
        foreach (['css/app.css', 'js/app/app.module.js'] as $own) {
            if (is_file($this->app->resourcesPath($own))) array_unshift($paths, '/' . $own);
        }
        return $paths;
    }

    // ---------------------------------------------------------------------------------------------------- the real web server

    private function liveCheck(string $url, int $timeout, Output $output): void
    {
        $url = rtrim($url, '/') . '/';
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            $output->error('--url needs a full address, e.g. http://localhost/my-app/public/');
            $this->fail++;
            return;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $folder = rtrim((string) ($parts['path'] ?? ''), '/');
        $configured = (string) Config::get('app.base_path', '');

        $output->line();
        $output->line($output->color("Your web server ($url)", 'orange'));
        [$status, $headers, $body] = $this->fetch($url, $timeout);
        if ($status === 0) {
            $this->row($output, false, 'could not reach ' . $url . ' (is the server running? is the address right?)');
            return;
        }
        $this->row($output, $status === 200, "page: $status " . ($headers['content-type'] ?? '') . ', ' . strlen($body) . ' bytes');
        if ($status === 200 && preg_match('#<title>Index of|<h1>Index of#i', $body)) {
            $this->row($output, false, "that is the web server's folder listing, not your app: nothing sent the request to public/index.php. Put a .htaccess next to public/ (php cast init writes it; or copy it from the starter) with:  RewriteEngine On / RewriteRule ^\$ public/ [L] / RewriteRule ^(.*)\$ public/\$1 [L]  and make sure the file is named exactly .htaccess (not .htaccess.txt), mod_rewrite is on and AllowOverride All is set. Or open http://localhost/your-app/public/ and set APP_BASE_PATH=/your-app/public");
            return;
        }
        if ($status !== 200) {
            if (preg_match('#\.\w{2,5}/?$#', (string) ($parts['path'] ?? ''))) $output->warn('  note  --url is the address of a page of your app (for example ' . $origin . '/), not of a file: ' . $url . ' looks like a file');
            if (str_contains($body, 'The requested resource') && str_contains($body, 'was not found on this server')) $output->warn('  note  that 404 page is PHP\'s own (the built-in server\'s), not the app\'s: if you started the server yourself, start it with the router script:  php cast serve   or   php -S 127.0.0.1:8000 -t public public/index.php');
            return;
        }

        if ($folder !== $configured) {
            $this->row($output, false, "APP_BASE_PATH is \"$configured\" but the address has the folder \"$folder\": set  APP_BASE_PATH=$folder  in .env (links to CSS and JS are built from it)", false);
        }

        preg_match_all('#<(?:link|script)\b[^>]*?(?:href|src)=["\']([^"\']+)["\']#i', $body, $m);
        $assets = [];
        foreach ($m[1] as $ref) {
            if (preg_match('#^(https?:)?//#i', $ref) && !str_starts_with($ref, $origin)) continue;      // fonts and CDNs are not ours
            $assets[] = $ref;
        }
        $assets = array_values(array_unique([...$assets, $folder . '/cdocs/', $folder . '/cdocs/data.js']));
        foreach ($assets as $ref) {
            $target = preg_match('#^https?://#i', $ref) ? $ref : $origin . (str_starts_with($ref, '/') ? '' : $folder . '/') . $ref;
            [$code, $h, $content] = $this->fetch($target, $timeout);
            $type = strtolower((string) ($h['content-type'] ?? ''));
            $expected = str_contains($ref, '.css') ? 'text/css' : (str_contains($ref, '.js') ? 'javascript' : '');
            $ok = $code === 200 && ($expected === '' || str_contains($type, $expected)) && $content !== '';
            $note = '';
            if ($code === 0) $note = ' (no answer)';
            elseif ($code === 404 && preg_match('#<address>|Apache|nginx|IIS|The requested URL#i', $content . ($h['server'] ?? '')) && !str_contains($content, 'The requested resource')) $note = ' (the web server\'s own 404 (' . ($h['server'] ?? 'no Server header') . '): the request never reached index.php. Apache: a .htaccess in the app folder (the one next to public/) must send requests to public/, public/.htaccess must send missing files to index.php, mod_rewrite must be on and AllowOverride All set. Compare with  http://localhost/your-app/login  which should answer from the framework)';
            elseif ($code === 404 && str_contains($content, 'The requested resource') && str_contains($content, 'was not found on this server')) $note = ' (this 404 is PHP\'s own: the built-in server was started without the router script. Stop it and run  php cast serve  or  php -S 127.0.0.1:8000 -t public public/index.php)';
            elseif ($code === 404) $note = $folder !== $configured ? ' (404: the address folder and APP_BASE_PATH differ)' : ' (404: the web server did not send this to index.php, or the file is not in the static folder)';
            elseif ($code === 200 && $expected !== '' && !str_contains($type, $expected)) $note = ' (a ' . ($type ?: 'page') . ' was returned for a file: the web server answered with another page, usually the home page; check the rewrite rules and APP_BASE_PATH)';
            elseif ($code === 200 && $content === '') $note = ' (empty answer: output compression or a proxy cut it; try the file in the browser address bar)';
            elseif (isset($h['content-length']) && (int) $h['content-length'] !== strlen($content) && !isset($h['content-encoding'])) $note = ' (the size sent (' . $h['content-length'] . ') differs from the size received (' . strlen($content) . '))';
            elseif ($code >= 300 && $code < 400) $note = ' (redirect to ' . ($h['location'] ?? '?') . ')';
            $this->row($output, $ok || ($code >= 300 && $code < 400), $this->shorten($target, $origin) . ": $code " . ($type ?: '') . ', ' . strlen($content) . ' bytes' . $note);
        }
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} */
    private function fetch(string $url, int $timeout): array
    {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "Accept: */*\r\nAccept-Encoding: identity\r\nUser-Agent: cast-static-check\r\n"], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($url, false, $context);
        $lines = $http_response_header ?? [];
        if ($body === false || !$lines) return [0, [], ''];
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $s)) {
                $status = (int) $s[1];
                $headers = [];                       // keep the headers of the last response (after redirects)
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return [$status, $headers, $body];
    }

    // ---------------------------------------------------------------------------------------------------- output

    private function row(Output $output, bool $ok, string $text, bool $soft = false): void
    {
        if ($ok) {
            $output->info('  ok    ' . $text);
            return;
        }
        if ($soft) {
            $output->warn('  note  ' . $text);
            return;
        }
        $this->fail++;
        $output->error('  FAIL  ' . $text);
    }

    private function short(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $this->app->basePath()), '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function shorten(string $url, string $origin): string
    {
        return str_starts_with($url, $origin) ? substr($url, strlen($origin)) : $url;
    }
}
