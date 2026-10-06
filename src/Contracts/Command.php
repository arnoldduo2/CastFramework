<?php

declare(strict_types=1);

namespace Cast\Contracts;

use Cast\Console\Input;
use Cast\Console\Output;

/** A console command, run with `php cast <name>`. */
interface Command
{
    public function name(): string;

    public function description(): string;

    /** @return int Exit code (0 = success). */
    public function handle(Input $input, Output $output): int;
}
