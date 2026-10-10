<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\Middleware;
use Cast\Core\Config;
use Cast\Core\Modules\Modules;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * `Router::module('reports', fn() => ...)` puts this in front of the routes inside, or by hand `Router::middleware([ModuleGate::class, 'reports'], ...)`.
 * With module gating off (the default) it does nothing. With it on, a module that is inactive, unbuilt or not listed answers with the page
 * `errors/module` (the framework has one; make `errors/module.cast.php` in your views to change it) or JSON for APIs and the SPA client, status 503 (403 for a module that belongs to a higher plan).
 */
final class ModuleGate implements Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response
    {
        $app = Application::instance();
        if (!$app || !$app->has('modules')) return null;
        /** @var Modules $modules */
        $modules = $app->make('modules');
        if (!$modules->enabled()) return null;

        $name = (string) ($args[0] ?? '');
        $status = $modules->status($name);
        if ($status['state'] === 'active') return null;

        $data = ['module' => $status, 'plan' => $modules->tier(), 'upgradeUrl' => (string) Config::get('modules.upgrade_url', '')];
        if ($status['state'] === 'locked') {
            // not a fault and not temporary: the plan does not include it
            throw new HttpException(403, $status['title'] . ' is not part of your plan.', [], 'errors.module', $data);
        }
        throw new HttpException(503, $status['title'] . ' is not available right now.', ['Retry-After' => '300'], 'errors.module', $data);
    }
}
