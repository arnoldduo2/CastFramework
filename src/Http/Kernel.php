<?php

declare(strict_types=1);

namespace Cast\Http;

use Cast\Support\DevError;
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
        $this->catchFatals($request);

        $early = $bootstrap->handle($request);
        if ($early !== null) {
            $early->send();
            return;
        }

        $this->app->boot();
        $bootstrap->decorate($this->handle($request), $request)->send();
    }

    /**
     * A PHP fatal error (a compile error in a view, memory exhausted...) cannot be caught as an exception. PHP would print it
     * into the page, or leave an empty 500 that a Cast client can only retry. Instead: log it, and answer with the same error
     * response as any other failure (JSON for API and Cast clients, the error page for browsers).
     */
    private function catchFatals(Request $request): void
    {
        if (PHP_SAPI === 'cli') return;
        ini_set('display_errors', '0');
        register_shutdown_function(function () use ($request) {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) return;
            error_log(sprintf('Fatal error: %s in %s:%d', $error['message'], $error['file'], $error['line']));
            if (headers_sent()) return;
            // for a browser the error handler (when it is on) shows its own page for fatal errors: leave it to it
            $machine = $request->isCast() || $request->isApi() || $request->expectsJson();
            if (!$machine && $this->app->has('error_handler')) return;
            while (ob_get_level() > 0) @ob_end_clean();

            $debug = (bool) Config::get('app.debug', false);
            $details = DevError::capture($error, $this->app);
            $file = (string) $error['file'];
            // a compiled view says which template it came from on its first line
            if (is_file($file) && preg_match('#^<\?php (?:declare\(strict_types=[01]\); )?/\* (.+?) \*/ \?>#', (string) file_get_contents($file, false, null, 0, 1000), $m)) $file = $m[1];
            $file = str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $file);
            $message = $debug ? sprintf('%s (%s:%d)', $error['message'], $file, $error['line']) : '';
            try {
                $response = $this->fatalResponse($request, $message, $details);
            } catch (\Throwable) {
                $response = Response::html('<h1>500</h1><p>' . htmlspecialchars($message !== '' ? $message : 'Something went wrong on our side.', ENT_QUOTES) . '</p>', 500);
            }
            $response->send();
            // nothing may add to this answer: shutdown functions registered later (the error handler's HTML page) must not run
            exit(1);
        });
    }

    private function fatalResponse(Request $request, string $message, ?array $details = null): Response
    {
        if ($request->isCast() || $request->isApi() || $request->expectsJson()) {
            $text = $message !== '' ? $message : 'Something went wrong on our side. Please try again shortly.';
            return $request->isCast() || $details !== null ? DevError::envelope($text, $details, $request->isCast()) : Response::error($text, 500);
        }
        return $this->httpError(new HttpException(500, $message), $request);
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

        // development only: the full page of an error a Cast or API client got as JSON (not a route: it must not show in route:list)
        if ($request->method() === 'GET' && DevError::enabled() && preg_match('#^/cast/error/([0-9a-f]{8})$#', $request->path(), $m)) {
            return DevError::page($m[1], $this->app) ?? Response::html('<h1>404</h1><p>That error is no longer stored (the last 25 are kept), or the error handler is older than 1.3.</p>', 404);
        }

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
            return $this->serverError($e, $request);
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

    private function serverError(\Throwable $e, Request $request): Response
    {
        error_log(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        $debug = (bool) Config::get('app.debug', false);
        $message = $debug ? $e->getMessage() : 'Something went wrong on our side. Please try again shortly.';
        // development: where it happened, the code, the stack and a link to the full page (data.debug); production: nothing of that
        $details = DevError::capture($e, $this->app);
        return $request->isCast() || $details !== null ? DevError::envelope($message, $details, $request->isCast()) : Response::error($message, 500);
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
