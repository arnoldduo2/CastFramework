<?php

declare(strict_types=1);

namespace Cast\Support;

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Core\Env;
use Cast\Http\Response;

/**
 * Errors for clients that cannot show an HTML error page: the Cast SPA client, a React / Vue / Next.js front end calling the API.
 * In development (`APP_DEBUG=true`, not production) a server error answers with the usual JSON plus `data.debug`: where it happened,
 * the code around it, the stack, a link that opens the file in your editor, and `url`, a full error page on the server that any browser
 * can open (the same page the error handler shows to normal requests). The Cast client shows it as an overlay; a front end can use
 * `/cast/error-overlay.js` or read the JSON itself. Never in production: there `data.debug` does not exist.
 *
 * Needs `anode/error-handler` 1.3 for the full page and the editor links; without it `debug` still has the message, file, line, code and stack.
 */
final class DevError
{
    private const KEEP = 25;

    public static function enabled(): bool
    {
        return (bool) Config::get('app.debug', false) && Config::get('app.env') !== 'production';
    }

    /**
     * The `debug` payload for an error (an exception, or error_get_last() of a fatal error), or null outside development.
     * @param \Throwable|array{type?: int, message: string, file: string, line: int} $error
     * @return array<string, mixed>|null
     */
    public static function capture($error, Application $app): ?array
    {
        if (!self::enabled()) return null;
        try {
            $options = ['root_path' => $app->basePath(), 'editor' => (string) (Env::get('CAST_EDITOR') ?: 'vscode'), 'app_name' => (string) Config::get('app.name', ''), 'app_enviroment' => (string) Config::get('app.env', '')];
            $report = class_exists(\Anode\ErrorHandler\Report::class) ? \Anode\ErrorHandler\Report::make($error, $options) : self::basicReport($error, $app);
            $page = self::pageAvailable();
            if ($page) self::store($report, $app);

            $frame = class_exists(\Anode\ErrorHandler\CodeFrame::class) ? \Anode\ErrorHandler\CodeFrame::lines((string) $report['file'], (int) $report['line'], 6) : self::lines((string) $report['file'], (int) $report['line'], 6);
            $code = [];
            foreach ($frame['lines'] ?? [] as $n => $text) $code[] = ['n' => $n, 'text' => mb_scrub((string) $text), 'error' => $n === (int) $report['line']];
            $focus = $frame && class_exists(\Anode\ErrorHandler\CodeFrame::class) ? \Anode\ErrorHandler\CodeFrame::focus((string) $report['message'], (string) ($frame['lines'][(int) $report['line']] ?? '')) : null;

            return [
                'id' => $report['id'],
                'kind' => $report['kind'],
                'severity' => $report['severity'],
                'message' => $report['message'],
                'file' => $report['relative'],
                'line' => (int) $report['line'],
                'editor' => $report['editor'] ?? null,
                'url' => $page ? self::origin() . route('/cast/error/' . $report['id']) : null,   // absolute: a front end on another port opens it too
                'code' => $code,
                'focus' => $focus,                                    // [offset, length] of the failing part of that line
                'trace' => array_map(static fn(array $f) => [
                    'index' => $f['index'], 'file' => $f['relative'], 'line' => (int) $f['line'], 'context' => $f['context'], 'app' => $f['app'], 'editor' => $f['editor'] ?? null,
                ], array_slice($report['frames'], 0, 30)),
            ];
        } catch (\Throwable) {
            return null;                          // reporting an error must never be the error
        }
    }

    /** The JSON answer for an error: the usual envelope, `data.debug` added in development. */
    public static function envelope(string $message, ?array $debug, bool $cast, int $status = 500): Response
    {
        $data = $cast ? ['type' => 'error', 'code' => $status] : [];
        if ($debug !== null) $data['debug'] = $debug;
        return Response::json(['status' => 'error', 'msg' => $message, 'data' => (object) $data], $status);
    }

    /** `GET /cast/error/<id>`: the stored report as the handler's full development page. */
    public static function page(string $id, Application $app): ?Response
    {
        if (!self::enabled() || !preg_match('/^[0-9a-f]{8}$/', $id) || !self::pageAvailable()) return null;
        $file = $app->storagePath("framework/errors/$id.json");
        $report = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($report)) return null;

        $view = dirname((new \ReflectionClass(\Anode\ErrorHandler\Report::class))->getFileName()) . '/views/handler.php';
        if (!is_file($view)) return null;
        $render = static function (array $vars) use ($view): string {
            extract($vars);
            ob_start();
            include $view;
            return (string) ob_get_clean();
        };
        return Response::html($render(['report' => $report, 'APP_NAME' => (string) Config::get('app.name', 'App'), 'ROOT_PATH' => route('/'), 'snippet_lines' => 6]), 500);
    }

    /** scheme://host of this request (empty on the command line) */
    private static function origin(): string
    {
        if (!isset($_SERVER['HTTP_HOST'])) return '';
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) $_SERVER['HTTP_HOST']);
    }

    /** The full page needs the handler's report and its view (handler 1.3). */
    private static function pageAvailable(): bool
    {
        return class_exists(\Anode\ErrorHandler\Report::class) && class_exists(\Anode\ErrorHandler\CodeFrame::class);
    }

    /** @param array<string, mixed> $report */
    private static function store(array $report, Application $app): void
    {
        $dir = $app->storagePath('framework/errors');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents("$dir/{$report['id']}.json", json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
        $files = glob("$dir/*.json") ?: [];
        if (count($files) > self::KEEP) {
            usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - self::KEEP) as $old) @unlink($old);
        }
    }

    /** A report with what PHP itself knows, for when the handler package is older than 1.3. @return array<string, mixed> */
    private static function basicReport($error, Application $app): array
    {
        $root = rtrim(str_replace('\\', '/', $app->basePath()), '/');
        $relative = static function (string $file) use ($root): string {
            $file = str_replace('\\', '/', $file);
            return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
        };
        if ($error instanceof \Throwable) {
            $frames = [['file' => $error->getFile(), 'line' => $error->getLine(), 'context' => isset($error->getTrace()[0]['function']) ? (($error->getTrace()[0]['class'] ?? '') . ($error->getTrace()[0]['type'] ?? '') . $error->getTrace()[0]['function'] . '()') : '']];
            foreach ($error->getTrace() as $i => $step) {
                if (isset($step['file'])) $frames[] = ['file' => $step['file'], 'line' => $step['line'] ?? 0, 'context' => ''];
            }
            $kind = get_class($error);
            [$message, $file, $line] = [$error->getMessage(), $error->getFile(), $error->getLine()];
        } else {
            $kind = 'FatalError';
            [$message, $file, $line] = [(string) $error['message'], (string) $error['file'], (int) $error['line']];
            $frames = [['file' => $file, 'line' => $line, 'context' => '']];
        }
        foreach ($frames as $i => &$f) {
            $f['index'] = $i;
            $f['relative'] = $relative((string) $f['file']);
            $f['app'] = !str_contains(str_replace('\\', '/', (string) $f['file']), '/vendor/');
            $f['editor'] = null;
        }
        unset($f);
        return ['id' => substr(hash('sha1', $kind . $file . $line . $message), 0, 8), 'kind' => $kind, 'severity' => 'ERROR', 'message' => $message, 'file' => $file, 'line' => $line, 'relative' => $relative($file), 'editor' => null, 'frames' => $frames];
    }

    /** @return array{start: int, error: int, lines: array<int, string>}|null */
    private static function lines(string $file, int $line, int $context): ?array
    {
        if ($file === '' || $line < 1 || !is_file($file) || !is_readable($file) || filesize($file) > 2000000) return null;
        $all = preg_split('/\r\n|\n|\r/', (string) file_get_contents($file));
        if (!is_array($all) || $line > count($all)) return null;
        $start = max(1, $line - $context);
        $lines = [];
        for ($n = $start; $n <= min(count($all), $line + $context); $n++) $lines[$n] = $all[$n - 1];
        return ['start' => $start, 'error' => $line, 'lines' => $lines];
    }
}
