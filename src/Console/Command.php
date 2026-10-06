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

    public function __construct(protected Application $app) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    abstract public function handle(Input $input, Output $output): int;
}
