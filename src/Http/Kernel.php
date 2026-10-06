<?php

declare(strict_types=1);

namespace Cast\Http;

use Cast\App\Application;
use Cast\Boot\Bootstrap;
use Cast\Contracts\Middleware;
use Cast\Core\Config;
use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Core\Updates\UpdateManager;
use Cast\Core\View;
use Cast\Validation\ValidationException;

/**
 * The HTTP request pipeline: static files and preflight, boot the app, global middleware, update check,
 * routing, and error pages.
 */
final class Kernel
{
    public function __construct(private Application $app) {}

    public function run(): void
    {
        $request = Request::capture();
        $bootstrap = new Bootstrap($this->app);

        $early = $bootstrap->handle($request);
        if ($early !== null) {
            $early->send();
            return;
        }

        $this->app->boot();
        $bootstrap->decorate($this->handle($request), $request)->send();
    }

    /** Turn a request into a response (no output, no exit): this is what tests call. */
    public function handle(Request $request): Response
    {
        Request::setCurrent($request);

        try {
            foreach ((array) Config::get('app.middleware', []) as $spec) {
                $spec = (array) $spec;
                $class = array_shift($spec);
                $instance = new $class();
                if (!$instance instanceof Middleware) {
                    throw new \InvalidArgumentException("$class must implement " . Middleware::class . '.');
                }
                $response = $instance->handle($request, ...$spec);
                if ($response !== null) return $response;
            }

            UpdateManager::check($this->app);

            return Router::dispatch($request);
        } catch (HttpException $e) {
            return $this->httpError($e, $request);
        } catch (ValidationException $e) {
            return $this->validationError($e, $request);
        }
    }

    private function httpError(HttpException $e, Request $request): Response
    {
        $code = $e->statusCode();
        $message = $e->getMessage();

        if ($request->expectsJson()) {
            $response = Response::error($message, $code);
        } else {
            $view = $this->app->has('view') ? $this->app->make('view') : null;
            $html = $view instanceof View
                ? $view->errorPage($code, $message, $e->view, $e->data)
                : '<h1>' . $code . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
            $response = Response::html($html, $code);
        }

        foreach ($e->headers() as $name => $value) $response->header($name, $value);
        return $response->status($code);
    }

    private function validationError(ValidationException $e, Request $request): Response
    {
        if ($request->expectsJson()) {
            return Response::error($e->getMessage(), 422, $e->errors());
        }

        Session::flash('input_errors', $e->errors());
        Session::flash('old', array_diff_key($request->all(), array_flip(['password', 'password_confirmation', '_token'])));
        return Response::redirect($request->header('Referer') ?? route('/'));
    }
}
