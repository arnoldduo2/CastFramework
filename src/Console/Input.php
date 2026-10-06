<?php

declare(strict_types=1);

namespace Cast\Console;

/** Parsed command line: `php cast make:controller Invoice --force --path=app -v`. */
final class Input
{
    /** @var list<string> */
    private array $arguments = [];
    /** @var array<string, string|bool> */
    private array $options = [];
    private string $command;

    /** @param list<string> $argv Arguments after the script name. */
    public function __construct(array $argv)
    {
        $this->command = (string) (array_shift($argv) ?? '');
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = explode('=', substr($arg, 2), 2);
                $this->options[$pair[0]] = $pair[1] ?? true;
            } elseif (strlen($arg) > 1 && $arg[0] === '-') {
                foreach (str_split(substr($arg, 1)) as $flag) $this->options[$flag] = true;
            } else {
                $this->arguments[] = $arg;
            }
        }
    }

    public function command(): string
    {
        return $this->command;
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, string|bool|int|null $default = null): string|bool|int|null
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
