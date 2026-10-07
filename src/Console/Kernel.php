<?php

declare(strict_types=1);

namespace Cast\Console;

use Cast\App\Application;
use Cast\Contracts\Command as CommandContract;
use Cast\Core\Config;

/**
 * Runs `php cast <command>`. Built-in commands plus any listed in `config('console.commands')`.
 */
final class Kernel
{
    private const BUILT_IN = [
        Commands\ServeCommand::class,
        Commands\RouteListCommand::class,
        Commands\ViewsClearCommand::class,
        Commands\DownCommand::class,
        Commands\UpCommand::class,
        Commands\EnvCheckCommand::class,
        Commands\VersionCommand::class,
        Commands\InitCommand::class,
        Commands\TokenCreateCommand::class,
        Commands\TokenRevokeCommand::class,
        Commands\TokenSchemaCommand::class,
    ];

    /** @var array<string, CommandContract> */
    private array $commands = [];

    public function __construct(private Application $app, private ?Output $output = null)
    {
        $this->output ??= new Output();
        foreach ([...self::BUILT_IN, ...(array) Config::get('console.commands', [])] as $class) {
            $this->add(new $class($app));
        }
        // make:* commands share one class with several names
        foreach (Commands\MakeCommand::kinds() as $kind) {
            $this->add(new Commands\MakeCommand($app, $kind));
        }
    }

    public function add(CommandContract $command): void
    {
        if ($command->name() !== '') $this->commands[$command->name()] = $command;
    }

    /** @param list<string> $argv Arguments after the script name. @return int Exit code. */
    public function run(array $argv): int
    {
        $input = new Input($argv);
        $name = $input->command();

        if ($name === '' || $name === 'list' || $name === 'help' || $name === '--help') {
            return $this->list();
        }
        if (!isset($this->commands[$name])) {
            $this->output->error("Command \"$name\" is not defined.");
            $suggest = array_filter(array_keys($this->commands), fn($c) => str_starts_with($c, explode(':', $name)[0]));
            if ($suggest) $this->output->line('Did you mean: ' . implode(', ', $suggest) . '?');
            return 1;
        }

        $this->app->boot();
        return $this->commands[$name]->handle($input, $this->output);
    }

    private function list(): int
    {
        $this->output->line('CastFramework ' . Application::VERSION);
        $this->output->line();
        $this->output->line('Usage: php cast <command> [arguments] [--options]');
        $this->output->line();

        $rows = [];
        ksort($this->commands);
        foreach ($this->commands as $name => $command) $rows[] = [$name, $command->description()];
        $this->output->table(['Command', 'Description'], $rows);
        return 0;
    }
}
