<?php

declare(strict_types=1);

/** A view that PHP refuses to compile (declare(strict_types) after the engine's own first line): an error response, never a loop or raw PHP output. */
function fatal_app(): string
{
    $dir = init_dir();
    cast_in($dir, 'init');
    file_put_contents("$dir/resources/views/home/partials/home.cast.php", "<?php\n\ndeclare(strict_types=1);\n?>\n<p>hello</p>\n");
    return $dir;
}

/** @return array{int, string} status and body of a request to the built-in server serving $dir */
function dev_server_get(string $dir, array $headers): array
{
    $port = random_int(20000, 40000);
    $proc = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg("$dir/public")), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]]);
        $body = (string) @file_get_contents("http://127.0.0.1:$port/", false, $ctx);
        preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), $body];
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

test('views:check finds views that start with declare(strict_types) and --fix removes the line', function () {
    $dir = fatal_app();
    [$code, $out] = cast_in($dir, 'views:check');
    eq(1, $code, $out);
    has('home.cast.php', $out);
    has('very first statement', $out);
    [$code, $out] = cast_in($dir, 'views:check --fix');
    eq(0, $code, $out);
    has('fixed', $out);
    eq("<?php\n\n?>\n<p>hello</p>\n", file_get_contents("$dir/resources/views/home/partials/home.cast.php"));
    eq(0, cast_in($dir, 'views:check')[0]);
    ok(is_file("$dir/resources/views/home/partials/home.cast.php"));
});

test('client: an HTTP error without a Cast body is shown as an error, never reloaded in a loop', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resources/cast.module.js');
    has('!(res.http >= 400)', $js);
    has('recentFallback', $js);
    has('The server could not complete the request', $js);
});

test('a PHP fatal error in a view is answered as an error: JSON for Cast clients, no raw PHP output, details only in debug', function () {
    $dir = fatal_app();
    file_put_contents("$dir/.env", preg_replace('/APP_DEBUG=.*/', 'APP_DEBUG=true', (string) file_get_contents("$dir/.env")));
    [$status, $body] = dev_server_get($dir, ['X-Cast-Request: 1']);
    eq(500, $status, $body);
    $json = json_decode($body, true);
    eq('error', $json['status'] ?? null, $body);
    has('strict_types declaration must be the very first statement', $json['msg']);
    has('home.cast.php', $json['msg'], 'names the template, not the cache file');
    lacks('Fatal error:', $body);

    file_put_contents("$dir/.env", preg_replace('/APP_DEBUG=.*/', 'APP_DEBUG=false', (string) file_get_contents("$dir/.env")));
    [$status, $body] = dev_server_get($dir, ['X-Cast-Request: 1']);
    eq(500, $status);
    lacks('strict_types', $body);
    has('Something went wrong', $body);
});
