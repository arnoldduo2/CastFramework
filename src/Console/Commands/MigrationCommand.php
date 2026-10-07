<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Contracts\Migrator;
use Cast\Core\Config;
use Cast\Database\MigrationException;
use Cast\Database\SeederRunner;
use Throwable;

/** Shared parts of the migration commands: the production guard, the migrator, progress output and seeding. */
abstract class MigrationCommand extends Command
{
    final public function handle(Input $input, Output $output): int
    {
        if (!$this->allowed($input, $output)) return 1;

        try {
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');
            $migrator->onProgress(fn(string $line) => $output->line($line));
            $code = $this->execute($migrator, $input, $output);
            if ($code === 0 && $input->hasOption('seed') && $this->seeds()) $this->seed($output);
            return $code;
        } catch (MigrationException $e) {
            $output->error($e->getMessage());
            return 1;
        } catch (Throwable $e) {
            $output->error(($e instanceof \PDOException ? 'Database error: ' : '') . $e->getMessage());
            return 1;
        }
    }

    abstract protected function execute(Migrator $migrator, Input $input, Output $output): int;

    /** Does `--seed` apply to this command? */
    protected function seeds(): bool
    {
        return false;
    }

    /** Changing the database in production needs --force. */
    protected function allowed(Input $input, Output $output): bool
    {
        if (Config::get('app.env') === 'production' && !$input->hasOption('force') && !$input->hasOption('pretend')) {
            $output->error('The application is in production. Add --force to run this command.');
            return false;
        }
        return true;
    }

    protected function seed(Output $output): void
    {
        $output->line('Seeding database');
        $output->info('Seeded: ' . SeederRunner::run());
    }

    /** @param array<string, list<string>> $pretended */
    protected function printPretended(array $pretended, Output $output): void
    {
        foreach ($pretended as $name => $statements) {
            $output->line();
            $output->line("-- $name");
            foreach ($statements as $sql) $output->line($sql . ';');
        }
    }
}
