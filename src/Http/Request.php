<?php

declare(strict_types=1);

namespace Cast\Http;

use Cast\Core\Config;
use Cast\Validation\Validator;

/**
 * The current HTTP request: method, path, query string, body (JSON or form, for every verb),
 * headers, files. Input is returned raw; use {@see sanitize()} or `getPost()` for sanitised data.
 */
final class Request
{
    private static ?self $current = null;

    /** @var array<string, mixed>|null */
    private ?array $body = null;
    /** @var array<string, string> */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $post   `$_POST` (multipart/form POSTs); otherwise the body is parsed from $rawBody
     */
    public function __construct(
        private string $method = 'GET',
        private string $uri = '/',
        private array $query = [],
        private string $rawBody = '',
        private array $server = [],
        private array $files = [],
        private array $cookies = [],
        private array $post = [],
    ) {
        $this->method = strtoupper($method);
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $query = $_GET;
        return self::$current = new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $query,
            PHP_SAPI === 'cli' ? '' : (string) file_get_contents('php://input'),
            $_SERVER,
            $_FILES,
            $_COOKIE,
            $_POST,
        );
    }

    public static function current(): self
    {
        return self::$current ??= self::capture();
    }

    public static function setCurrent(?self $request): void
    {
        self::$current = $request;
    }

    // ------------------------------------------------------------ method/path

    /** The HTTP method, honouring `_method` and `X-HTTP-Method-Override` on POST requests (HTML forms). */
    public function method(): string
    {
        if ($this->method !== 'POST') return $this->method;

        $override = $this->header('X-HTTP-Method-Override') ?? ($this->body()['_method'] ?? null);
        $override = is_string($override) ? strtoupper($override) : '';
        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : 'POST';
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /** The path relative to the app base path (`app.base_path`), always starting with "/". */
    public function path(): string
    {
        $path = (string) (parse_url($this->uri, PHP_URL_PATH) ?: '/');
        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function url(): string
    {
        $scheme = (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($this->server['HTTP_HOST'] ?? 'localhost') . $this->uri;
    }

    // ------------------------------------------------------------------ input

    /** @return mixed One query-string value, or all of them. */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    /** @return array<string, mixed> The parsed body (JSON or form-urlencoded), for any verb. */
    public function body(): array
    {
        if ($this->body !== null) return $this->body;
        if ($this->post) return $this->body = $this->post;

        $raw = trim($this->rawBody);
        if ($raw === '') return $this->body = [];

        $type = strtolower((string) $this->header('Content-Type'));
        if (str_contains($type, 'json') || (!str_contains($type, 'form-urlencoded') && in_array($raw[0], ['{', '['], true))) {
            $data = json_decode($raw, true);
            return $this->body = is_array($data) ? $data : [];
        }
        parse_str($raw, $data);
        return $this->body = $data;
    }

    public function raw(): string
    {
        return $this->rawBody;
    }

    /** Query string and body together (body wins). */
    public function all(): array
    {
        return $this->body() + $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string|array $keys): bool
    {
        $all = $this->all();
        foreach ((array) $keys as $key) {
            if (!array_key_exists($key, $all)) return false;
        }
        return true;
    }

    /** Present and not empty ('' / [] / null). */
    public function filled(string|array $keys): bool
    {
        $all = $this->all();
        foreach ((array) $keys as $key) {
            if (!isset($all[$key]) || $all[$key] === '' || $all[$key] === []) return false;
        }
        return true;
    }

    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /** @return array<string, mixed>|null An uploaded file entry from `$_FILES`. */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->routeParams : ($this->routeParams[$key] ?? $default);
    }

    // ---------------------------------------------------------------- sanitise

    /**
     * Run data through the configured sanitiser (`request.sanitizer`, a callable). Without one, strings are trimmed.
     * Use it where input becomes output or storage; the request itself stays raw.
     */
    public function sanitize(array $data): array
    {
        $sanitizer = Config::get('request.sanitizer');
        if (is_callable($sanitizer)) {
            $result = $sanitizer($data);
            return is_array($result) ? $result : [];
        }
        return self::trimAll($data);
    }

    /**
     * What `getPost()` returns: the JSON body (or, with `$form`, the form-encoded body) sanitised.
     * @return array<string, mixed>
     */
    public function postData(bool $form = false): array
    {
        $raw = trim($this->rawBody);
        if ($form) {
            parse_str($raw, $data);
        } else {
            $data = json_decode($raw, true);
        }
        return is_array($data) && $data ? $this->sanitize($data) : [];
    }

    private static function trimAll(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? self::trimAll($value) : (is_string($value) ? trim($value) : $value);
        }
        return $data;
    }

    // ---------------------------------------------------------------- headers

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $value = $this->server['HTTP_' . $key] ?? (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? ($this->server[$key] ?? null) : null);
        return $value === null ? $default : (string) $value;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type')), 'json');
    }

    public function expectsJson(): bool
    {
        return $this->isAjax() || $this->isJson() || $this->isCast()
            || str_contains(strtolower((string) $this->header('Accept')), 'json');
    }

    // --------------------------------------------------------------- SPA (M2)

    /** True when the Cast client asks for a page/partial/modal envelope instead of a full document. */
    public function isCast(): bool
    {
        return $this->header('X-Cast-Request') === '1';
    }

    /** 'page' | 'partial' | 'modal' */
    public function castType(): string
    {
        $type = strtolower((string) $this->header('X-Cast-Type', 'page'));
        return in_array($type, ['page', 'partial', 'modal'], true) ? $type : 'page';
    }

    public function castTarget(): ?string
    {
        return $this->header('X-Cast-Target');
    }

    public function castGuard(): ?string
    {
        return $this->header('X-Cast-Guard');
    }

    // ------------------------------------------------------------------- CSRF

    /** The CSRF token sent with this request: body `_token`, or the X-CSRF-TOKEN / X-XSRF-TOKEN header. */
    public function csrfToken(): string
    {
        $body = $this->all();
        foreach (['_token', 'token', 'csrf_token', 'csrf'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) return $body[$key];
        }
        return (string) ($this->header('X-CSRF-TOKEN') ?? $this->header('X-XSRF-TOKEN') ?? '');
    }

    // ------------------------------------------------------------- validation

    /**
     * Validate the request input. Returns the validated data, or throws a ValidationException.
     * @param array<string, string|array> $rules
     */
    public function validate(array $rules, array $messages = [], array $labels = []): array
    {
        return Validator::make($this->all(), $rules, $messages, $labels)->validate();
    }
}
