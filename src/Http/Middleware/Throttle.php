<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\Middleware;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Rate limit: `[Throttle::class, 60, 1]` allows 60 requests per 1 minute for each caller (the API token, else the IP address),
 * counted across every route that uses the same limit. A third argument names a separate counter: `[Throttle::class, 5, 1, 'login']`.
 * Counters live in files under `storage/framework/throttle`. Responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining`
 * and `X-RateLimit-Reset`; going over answers 429 with `Retry-After`.
 *
 * Behind a proxy, make sure REMOTE_ADDR is the client address (configure the web server), or every caller shares one limit.
 */
final class Throttle implements Middleware
{
    /** Tests can set a fake clock. @var callable(): int|null */
    public static $clock = null;

    public function handle(Request $request, mixed ...$args): ?Response
    {
        $max = max(1, (int) ($args[0] ?? 60));
        $seconds = max(1, (int) ($args[1] ?? 1)) * 60;
        $now = self::$clock ? (int) (self::$clock)() : time();

        $who = $request->bearerToken() !== null ? 'token:' . substr(explode('|', $request->bearerToken())[0], 0, 32) : 'ip:' . $request->ip();
        $key = hash('sha256', $who . '|' . $max . '|' . $seconds . '|' . (string) ($args[2] ?? ''));

        [$count, $reset] = $this->hit($key, $now, $seconds);
        $remaining = max(0, $max - $count);
        $headers = ['X-RateLimit-Limit' => (string) $max, 'X-RateLimit-Remaining' => (string) $remaining, 'X-RateLimit-Reset' => (string) $reset];

        if ($count > $max) {
            throw new HttpException(429, 'Too many requests. Try again in ' . max(1, $reset - $now) . ' seconds.', $headers + ['Retry-After' => (string) max(1, $reset - $now)]);
        }

        foreach ($headers as $name => $value) $request->addResponseHeader($name, $value);
        return null;
    }

    /** Now and then, remove counters nobody has touched for a day. */
    private function collectGarbage(string $dir, int $now): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@filemtime($file) < $now - 86400) @unlink($file);
        }
    }

    /** @return array{int, int} requests so far in the window (including this one), and when the window ends */
    private function hit(string $key, int $now, int $seconds): array
    {
        $dir = Application::instance()->storagePath('framework/throttle');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        if (random_int(1, 100) === 1) $this->collectGarbage($dir, $now);

        $handle = @fopen($dir . DIRECTORY_SEPARATOR . $key . '.json', 'c+');
        if ($handle === false) return [1, $now + $seconds];   // cannot count: do not block the request

        try {
            flock($handle, LOCK_EX);
            $state = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($state) || ($state['reset'] ?? 0) <= $now) {
                $state = ['count' => 0, 'reset' => $now + $seconds];
            }
            $state['count']++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);
            return [$state['count'], $state['reset']];
        } finally {
            fclose($handle);
        }
    }
}
