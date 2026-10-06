<?php

declare(strict_types=1);

namespace Cast\Contracts;

use Cast\Http\Request;
use Cast\Http\Response;

/**
 * Route middleware. Return null to continue, a Response to short-circuit,
 * or throw {@see \Cast\Http\HttpException} to stop with an error page.
 * Registered with `Router::middleware([MyMiddleware::class, ...$args], fn)`:
 * the extra array items are passed after the request.
 */
interface Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response;
}
