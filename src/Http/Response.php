<?php

declare(strict_types=1);

namespace Cast\Http;

/**
 * An HTTP response. Controllers can return one; the router sends it.
 *
 *   return Response::success('Saved!', ['id' => 5]);         // {"status":"success","msg":"Saved!","data":{"id":5}}
 *   return Response::error('Not allowed', 403);              // {"status":"error","msg":"Not allowed"}
 *   return Response::html($html);  Response::redirect('/login');
 */
class Response
{
    public const PHRASES = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found',
        304 => 'Not Modified', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Access Denied',
        404 => 'Page Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict', 419 => 'Page Expired',
        422 => 'Unprocessable Content', 429 => 'Too Many Requests', 500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    /** @var array<string, string> lower-cased name => value */
    private array $headers = [];
    private ?string $file = null;

    /** @param array<string, string> $headers */
    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) $this->header($name, $value);
    }

    public static function html(string $body, int $status = 200): static
    {
        return new static($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): static
    {
        return new static(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** `{status:'success', msg, data}` (the app-wide AJAX contract). */
    public static function success(string $msg = '', array $data = [], int $status = 200): static
    {
        return static::json(['status' => 'success', 'msg' => $msg, 'data' => (object) $data], $status);
    }

    /** `{status:'error', msg}`, plus `data.errors` when field errors are given. */
    public static function error(string $msg = '', int $status = 400, array $errors = []): static
    {
        $payload = ['status' => 'error', 'msg' => $msg];
        if ($errors) $payload['data'] = ['errors' => $errors];
        return static::json($payload, $status);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new static('', $status, ['Location' => $url]);
    }

    public static function noContent(): static
    {
        return new static('', 204);
    }

    /** Stream a file from disk. */
    public static function file(string $path, string $contentType): static
    {
        $response = new static('', 200, ['Content-Type' => $contentType]);
        $response->file = $path;
        $response->header('Content-Length', (string) filesize($path));
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT');
        return $response;
    }

    public function status(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    public function body(?string $body = null): string|static
    {
        if ($body === null) return $this->body;
        $this->body = $body;
        return $this;
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isJson(): bool
    {
        return str_contains((string) $this->getHeader('content-type'), 'json');
    }

    /** Decoded JSON body (for tests and middleware). */
    public function data(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($this->headerName($name) . ': ' . $value);
            }
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }

    private function headerName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', $name)));
    }
}
