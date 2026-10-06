<?php

declare(strict_types=1);

namespace Cast\Core;

/** A registered route. Returned by `Router::get()` etc. so options can be chained. */
final class Route
{
    /** @var list<string> permission slugs, any one is enough */
    public array $permissions = [];
    /** @var list<array> extra middleware specs for this route */
    public array $extraMiddleware = [];
    public bool $csrf = true;
    public ?string $name = null;
    private ?string $regex = null;

    /** @param list<array> $middleware group middleware specs in effect when registered */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly array $middleware = [],
    ) {}

    /** Permission slugs (any one is enough), checked with the bound Guard: `->middleware(['manage-users'])`. */
    public function middleware(array $slugs): static
    {
        $this->permissions = array_values(array_unique([...$this->permissions, ...array_map('strval', $slugs)]));
        return $this;
    }

    /** Add a middleware spec to this route: `->use([MyMiddleware::class, 'arg'])`. */
    public function use(array $spec): static
    {
        $this->extraMiddleware[] = $spec;
        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /** Skip the CSRF check for this route. */
    public function withoutCsrf(): static
    {
        $this->csrf = false;
        return $this;
    }

    /** @return array<string, string>|null Route params when the path matches, null otherwise. */
    public function match(string $path): ?array
    {
        if (!str_contains($this->path, '{')) {
            return $this->path === $path ? [] : null;
        }
        $this->regex ??= '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $this->path) . '$#';
        if (!preg_match($this->regex, $path, $m)) return null;
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
