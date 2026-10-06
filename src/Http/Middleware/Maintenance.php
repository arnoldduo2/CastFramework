<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\Middleware;
use Cast\Core\Maintenance\MaintenanceManager;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/** Shows the maintenance page (503) while maintenance mode is active, unless the request is allowed through. */
final class Maintenance implements Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response
    {
        $app = Application::instance();
        if (!$app || !$app->has('maintenance')) return null;

        /** @var MaintenanceManager $manager */
        $manager = $app->make('maintenance');
        $status = $manager->status();
        if (!$status['active'] || $manager->allows($request)) return null;

        $headers = $status['retry_after'] > 0 ? ['Retry-After' => (string) $status['retry_after']] : [];
        throw new HttpException(503, $status['message'], $headers, 'maintenance', ['status' => $status]);
    }
}
