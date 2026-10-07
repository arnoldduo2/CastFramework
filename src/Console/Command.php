<?php

declare(strict_types=1);

namespace Cast\Console;

use Cast\App\Application;
use Cast\Contracts\Command as CommandContract;

/** Base class for console commands. Set `$name` and `$description`, implement `handle()`. */
abstract class Command implements CommandContract
{
    protected string $name = '';
    protected string $description = '';
    /** Arguments in order: `'name' => 'what it is'`; a trailing `?` on the name makes it optional (`'table?'`). */
    protected array $arguments = [];
    /** Options: `'--force' => 'what it does'`, `'--table=NAME' => '...'`. */
    protected array $options = [];
    /** Examples: `'php cast migrate --pretend' => 'what it shows'`. */
    protected array $examples = [];

    public function __construct(protected Application $app) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** What `php cast help <command>` prints. @return array{arguments: array<string, string>, options: array<string, string>, examples: array<string, string>} */
    public function help(): array
    {
        return ['arguments' => $this->arguments, 'options' => $this->options, 'examples' => $this->examples];
    }

    abstract public function handle(Input $input, Output $output): int;
}
