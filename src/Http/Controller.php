<?php

declare(strict_types=1);

namespace Cast\Http;

use Cast\App\Application;
use Cast\Contracts\Guard;
use Cast\Core\Config;
use Cast\Core\View;
use Cast\Validation\Validator;
use Exception;

/**
 * Base class for every controller: the request, response helpers, validation, model lookup, authorisation.
 * Business logic belongs in services; this holds only what all controllers share.
 *
 * @property-read Request $request
 */
class Controller
{
    /** @var array<string, object> */
    protected array $model = [];

    /** @param class-string|string $model Optional model class to register under its own name. */
    public function __construct(string $model = '')
    {
        if ($model !== '') {
            $this->model[$model] = new $model();
        }
    }

    /** `$this->request` is the current request, without needing a constructor call. */
    public function __get(string $name): mixed
    {
        if ($name === 'request') return Request::current();
        throw new Exception('Undefined property ' . static::class . '::$' . $name);
    }

    // --------------------------------------------------------------- response

    protected function success(string $msg = '', array $data = [], int $status = 200): Response
    {
        return Response::success($msg, $data, $status);
    }

    protected function error(string $msg = '', int $status = 400, array $errors = []): Response
    {
        return Response::error($msg, $status, $errors);
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect(route($to), $status);
    }

    /** Render a view as a response (a full page, or the SPA envelope for Cast requests). */
    protected function view(string $view, array $data = [], int $status = 200): Response
    {
        $renderer = Application::instance()?->make('view');
        if (!$renderer instanceof View) {
            throw new Exception('The view renderer is not available.');
        }
        return $renderer->respond($view, $data, $status);
    }

    // ------------------------------------------------------------- validation

    /**
     * Validate the request input. Returns the validated data; on failure throws a ValidationException
     * (the Kernel answers 422 JSON, or flashes the errors and redirects back).
     * @param array<string, string|array> $rules
     */
    protected function validate(array $rules, array $messages = [], array $labels = []): array
    {
        return Validator::make($this->request->all(), $rules, $messages, $labels)->validate();
    }

    // ---------------------------------------------------------- authorisation

    /** Stop with 403 unless the bound Guard grants one of these permission slugs. */
    protected function authorize(string|array $permission): void
    {
        $app = Application::instance();
        $guard = $app && $app->has('guard') ? $app->make('guard') : null;
        if (!$guard instanceof Guard || !$guard->can($permission)) {
            throw new HttpException(403, 'You do not have the required permission: ' . implode(', ', (array) $permission));
        }
    }

    // ----------------------------------------------------------------- models

    /**
     * Find a model instance by name: already registered, a full class name, or a class in `config('models.namespace')`
     * ("customers" => "{namespace}\Customers" or "\Customer").
     */
    protected function getModelInstance(string $model): ?object
    {
        if (isset($this->model[$model]) && is_object($this->model[$model])) {
            return $this->model[$model];
        }

        $namespace = rtrim((string) Config::get('models.namespace', 'App\\Models'), '\\');
        foreach ([$model, $namespace . '\\' . ucfirst($model), $namespace . '\\' . ucfirst($model) . 's'] as $class) {
            if (class_exists($class)) {
                return $this->model[$model] = new $class();
            }
        }
        return null;
    }
}
