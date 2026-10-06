<?php

declare(strict_types=1);

namespace Cast\Http;

use RuntimeException;

/** Throw to stop a request with an HTTP error; the Kernel turns it into an error page or JSON. */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     * @param string|null $view  Error view to render instead of `errors.{code}` (e.g. 'maintenance')
     * @param array<string, mixed> $data Extra data for that view
     */
    public function __construct(
        private int $statusCode,
        string $message = '',
        private array $headers = [],
        public readonly ?string $view = null,
        public readonly array $data = [],
    ) {
        parent::__construct($message !== '' ? $message : (Response::PHRASES[$statusCode] ?? 'Error'), $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
