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
        Commands\ViewsCheckCommand::class,
        Commands\IdeHelpersCommand::class,
        Commands\KeyGenerateCommand::class,
        Commands\ModulesTableCommand::class,
        Commands\MakeModuleCommand::class,
        Commands\MakeServiceCommand::class,
        Commands\DeployInitCommand::class,
        Commands\DeployCheckCommand::class,
        Commands\DeployScanCommand::class,
        Commands\DeployOptimizeCommand::class,
        Commands\RequirementsCommand::class,
        Commands\DemoStripCommand::class,
        Commands\MakeConfigCommand::class,
        Commands\DocsBuildCommand::class,
        Commands\DownCommand::class,
        Commands\UpCommand::class,
        Commands\EnvCheckCommand::class,
        Commands\VersionCommand::class,
        Commands\InitCommand::class,
        Commands\EditorInstallCommand::class,
        Commands\MigrateSyncCommand::class,
        Commands\DbSequenceCommand::class,
        Commands\ComponentsCommand::class,
        Commands\MakeComponentCommand::class,
        Commands\MigrateCommand::class,
        Commands\MigrateRollbackCommand::class,
        Commands\MigrateResetCommand::class,
        Commands\MigrateRefreshCommand::class,
        Commands\MigrateFreshCommand::class,
        Commands\MigrateStatusCommand::class,
        Commands\MigrateBaselineCommand::class,
        Commands\MakeMigrationCommand::class,
        Commands\MakeSeederCommand::class,
        Commands\DbSeedCommand::class,
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
        foreach (Commands\ModulesCommand::actions() as $action) {
            $this->add(new Commands\ModulesCommand($app, $action));
        }
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

        if ($name === 'help') return $this->help($input);
        if ($name === '' || $name === 'list' || $name === '--help' || $name === '-h') {
            return $this->list();
        }
        if (!isset($this->commands[$name])) {
            $this->output->error("Command \"$name\" is not defined.");
            $suggest = array_filter(array_keys($this->commands), fn($c) => str_starts_with($c, explode(':', $name)[0]));
            if ($suggest) $this->output->line('Did you mean: ' . implode(', ', $suggest) . '?');
            return 1;
        }
        if ($input->hasOption('help') || $input->hasOption('h')) {
            Help::render($this->commands[$name], $this->output);
            return 0;
        }

        $this->app->boot();
        return $this->commands[$name]->handle($input, $this->output);
    }

    /** `php cast help`, `php cast help migrate:sync`, `php cast help --markdown [--write=PATH]` */
    private function help(Input $input): int
    {
        $target = $input->argument(0);
        if ($target === null || $target === '') {
            if (!$input->hasOption('markdown')) return $this->list();
            $md = Help::markdown($this->commands);
            $path = $input->option('write');
            if (is_string($path) && $path !== '') {
                $file = preg_match('#^([a-z]:)?[\\/]#i', $path) ? $path : $this->app->basePath($path);
                if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
                file_put_contents($file, $md);
                $this->output->info('Wrote ' . $path);
            } else {
                $this->output->line($md);
            }
            return 0;
        }
        if (!isset($this->commands[$target])) {
            $this->output->error("Command \"$target\" is not defined.");
            $near = array_filter(array_keys($this->commands), fn($c) => str_contains($c, $target) || str_starts_with($c, explode(':', $target)[0]));
            if ($near) $this->output->line('Did you mean: ' . implode(', ', $near) . '?');
            return 1;
        }
        Help::render($this->commands[$target], $this->output);
        return 0;
    }

    private function list(): int
    {
        $o = $this->output;
        $o->line($o->color('CastFramework', 'title') . ' ' . $o->color(Application::VERSION, 'grey'));
        $o->line();
        $o->line($o->color('Usage:', 'orange') . ' php cast <command> [arguments] [--options]');
        $o->line($o->color('Help:', 'orange') . '  php cast help <command>   (or  php cast <command> --help)');
        $o->line();

        // grouped by the part before the colon (migrate, make, db, token...); commands without one come first
        $groups = [];
        ksort($this->commands);
        foreach ($this->commands as $name => $command) {
            $prefix = str_contains($name, ':') ? explode(':', $name)[0] : '';
            $groups[$prefix][$name] = $command->description();
        }
        // a lone command such as `migrate` belongs with `migrate:*`; one with no relatives stays in the general list
        foreach ($groups[''] ?? [] as $name => $description) {
            if (isset($groups[$name])) {
                $groups[$name] = [$name => $description] + $groups[$name];
                unset($groups[''][$name]);
            }
        }
        ksort($groups);
        $width = max(array_map('strlen', array_keys($this->commands)));
        foreach ($groups as $prefix => $commands) {
            if (!$commands) continue;
            $o->line($o->color($prefix === '' ? 'General' : ucfirst($prefix), 'orange'));
            foreach ($commands as $name => $description) $o->line('  ' . $o->color(str_pad($name, $width), 'green') . '  ' . $description);
            $o->line();
        }
        return 0;
    }
}
