<?php

declare(strict_types=1);

namespace Cast\Http\Middleware;

use Cast\App\Application;
use Cast\Contracts\Middleware;
use Cast\Core\Modules\Modules;
use Cast\Http\HttpException;
use Cast\Http\Request;
use Cast\Http\Response;

/**
 * `Router::module('reports', fn() => ...)` puts this in front of the routes inside, or by hand `Router::middleware([ModuleGate::class, 'reports'], ...)`.
 * With module gating off (the default) it does nothing. With it on, a module that is inactive, unbuilt or not listed answers with the page
 * `errors/module` (the framework has one; make `errors/module.cast.php` in your views to change it) or JSON for APIs and the SPA client, status 503. An app rule added with `Modules::resolveUsing()` can change the status code and the texts (a plan lock answers 403).
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

        $code = (int) ($status['http'] ?? 503);
        $message = (string) ($status['message'] ?? $status['title'] . ' is not available right now.');
        throw new HttpException($code, $message, $code === 503 ? ['Retry-After' => '300'] : [], 'errors.module', ['module' => $status]);
    }
}
