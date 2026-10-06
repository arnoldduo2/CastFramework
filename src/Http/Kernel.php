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
        $response = $this->process($request);
        foreach ($request->responseHeaders() as $name => $value) {
            if ($response->getHeader($name) === null) $response->header($name, $value);
        }
        return $response;
    }

    private function process(Request $request): Response
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

            return $this->forClient(Router::dispatch($request), $request);
        } catch (HttpException $e) {
            return $this->httpError($e, $request);
        } catch (ValidationException $e) {
            return $this->validationError($e, $request);
        } catch (\Throwable $e) {
            // API and Cast (SPA) clients expect JSON even when the server fails; other requests go to the error handler
            if (!$request->isApi() && !$request->isCast()) throw $e;
            return $this->serverError($e);
        }
    }

    /** A redirect cannot be followed cleanly by a Cast (XHR) client, so it becomes an envelope the client navigates with. */
    private function forClient(Response $response, Request $request): Response
    {
        $location = $response->getHeader('Location');
        if ($request->isCast() && $location !== null && $response->statusCode() >= 300 && $response->statusCode() < 400) {
            return Response::success('', ['type' => 'redirect', 'url' => $location]);
        }
        return $response;
    }

    private function serverError(\Throwable $e): Response
    {
        error_log(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        $debug = (bool) Config::get('app.debug', false);
        return Response::error($debug ? $e->getMessage() : 'Something went wrong on our side. Please try again shortly.', 500);
    }

    private function httpError(HttpException $e, Request $request): Response
    {
        $code = $e->statusCode();
        $message = $e->getMessage();

        if ($request->isCast()) {
            // a maintenance or update page is a whole page: let the browser load it
            $response = $code === 503
                ? Response::json(['status' => 'error', 'msg' => $message, 'data' => ['type' => 'reload', 'code' => $code]], $code)
                : Response::json(['status' => 'error', 'msg' => $message !== '' ? $message : (Response::PHRASES[$code] ?? 'Error'), 'data' => ['type' => 'error', 'code' => $code]], $code);
        } elseif ($request->expectsJson()) {
            $response = Response::error($message !== '' ? $message : (Response::PHRASES[$code] ?? 'Error'), $code);
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
