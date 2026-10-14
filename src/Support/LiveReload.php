<?php

declare(strict_types=1);

namespace Cast\Support;

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Native Node-free Live-Reload controller for CastFramework.
 * Non-blocking, instant response (<1ms) tracking modification timestamps.
 * Releases session locks immediately so normal page loads and SPA navigations
 * never experience lag or worker starvation.
 */
final class LiveReload
{
    public static function enabled(): bool
    {
        return DevError::enabled() && Config::get('app.hmr', 'poll') !== 'disabled';
    }

    public static function handle(Application $app, Request $request): Response
    {
        // Immediately release any PHP session lock so other browser requests are never blocked
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        if (!self::enabled() || Config::get('app.hmr', 'poll') === 'vite') {
            return new Response('', 204);
        }

        $watchDirs = [
            $app->basePath('resources/views'),
            $app->basePath('resources/css'),
            $app->basePath('resources/js'),
            $app->basePath('routes'),
        ];

        clearstatcache();
        $latest = self::scanLatest($watchDirs);

        $since = (int) ($request->query('since') ?? 0);

        if ($since > 0 && $latest['time'] > $since) {
            return Response::json([
                'status'  => 'ok',
                'changed' => true,
                'type'    => $latest['type'],
                'file'    => $latest['file'],
                'time'    => $latest['time'],
            ]);
        }

        return Response::json([
            'status'  => 'ok',
            'changed' => false,
            'time'    => $latest['time'],
        ]);
    }

    /**
     * @param list<string> $dirs
     * @return array{time: int, file: string, type: string}
     */
    private static function scanLatest(array $dirs): array
    {
        $maxTime = 0;
        $latestFile = '';
        $type = 'reload';

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    /** @var \SplFileInfo $file */
                    if ($file->isFile()) {
                        $mtime = $file->getMTime();
                        if ($mtime > $maxTime) {
                            $maxTime = $mtime;
                            $latestFile = $file->getFilename();
                            $ext = strtolower($file->getExtension());
                            if ($ext === 'css') {
                                $type = 'css';
                            } elseif (str_ends_with($file->getPathname(), '.cast.php')) {
                                $type = 'view';
                            } else {
                                $type = 'reload';
                            }
                        }
                    }
                }
            } catch (\Throwable) {}
        }

        return ['time' => $maxTime, 'file' => $latestFile, 'type' => $type];
    }
}
