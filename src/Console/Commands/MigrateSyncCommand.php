<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;
use Cast\Database\Migrator as BuiltIn;

/**
 * `php cast migrate:sync` writes migration files for tables that already exist, so a legacy database can join the migration system:
 *
 *   php cast migrate:sync                       every table that has no migration yet
 *   php cast migrate:sync users                 one table
 *   php cast migrate:sync --table=users,orders  several
 *   php cast migrate:sync --except=logs,cache
 *   php cast migrate:sync --pretend             show the files, write nothing
 *   php cast migrate:sync --no-record           write the files but do not mark them as run
 *   php cast migrate:sync --collation=utf8mb4_unicode_ci   write every table with this collation (MySQL)
 *   php cast migrate:sync --auto-increment      also write each table's next auto-increment value
 *   php cast migrate:sync --init                test every relationship (foreign keys, and x_id columns without one); pass / warn / broken; writes nothing
 *
 * The files are recorded as already run, because the tables exist: `php cast migrate` will not try to create them again.
 */
final class MigrateSyncCommand extends MigrationCommand
{
    protected string $name = 'migrate:sync';
    protected string $description = 'Write migrations for tables that already exist: [table] [--table=a,b] [--except=a,b] [--pretend] [--no-record] [--collation=X] [--auto-increment] [--init] (--force in production)';

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        if (!$migrator instanceof BuiltIn) {
            $output->error('migrate:sync works with the built-in migrator. With another migration tool, use its own "generate from database" feature.');
            return 1;
        }

        $tables = $this->names($input->argument(0)) + [];
        $option = $input->option('table');
        if (is_string($option)) $tables = array_merge($tables, $this->names($option));
        $except = $this->names($input->option('except'));
        $pretend = $input->hasOption('pretend');

        $migrator->onProgress(null);
        if ($input->hasOption('init')) return $this->audit($migrator, $tables, $output);
        $results = $migrator->sync(['tables' => $tables ?: null, 'except' => $except, 'record' => !$input->hasOption('no-record'), 'pretend' => $pretend,
            'collation' => is_string($input->option('collation')) ? $input->option('collation') : null, 'autoIncrement' => $input->hasOption('auto-increment')]);

        $written = 0;
        foreach ($results as $r) {
            if ($r['status'] === 'skipped') {
                $output->line("Skipped  {$r['table']}: {$r['reason']}");
                continue;
            }
            $written++;
            $output->info(($pretend ? 'Would write  ' : 'Written  ') . $r['file']);
            foreach ($r['notes'] as $note) $output->warn("  note: $note");
            if ($pretend) $output->line("\n" . $r['code']);
        }

        if (!$written) {
            $output->line($results ? 'Nothing new to write.' : 'Every table already has a migration.');
            return 0;
        }
        if ($pretend) return 0;
        $output->line();
        $output->line(!$input->hasOption('no-record')
            ? 'The files were recorded as run (the tables already exist). From now on add changes with  php cast make:migration.'
            : 'The files are pending. Run  php cast migrate:baseline  to mark them as run without executing them.');
        $output->line('Look the files over: types the database has and migrations do not are written as the closest match (see the notes above).');
        return 0;
    }

    /** @param list<string> $tables */
    private function audit(BuiltIn $migrator, array $tables, Output $output): int
    {
        $results = $migrator->relationships($tables ?: null);
        if (!$results) {
            $output->line('No relationships found: no foreign keys, and no columns that look like one (thing_id).');
            return 0;
        }
        $counts = ['pass' => 0, 'warn' => 0, 'broken' => 0];
        $rows = [];
        foreach ($results as $r) {
            $counts[$r['status']]++;
            $rows[] = [strtoupper($r['status']), $r['table'] . '.' . implode('+', $r['columns']) . ' -> ' . $r['target'], $r['kind'], $r['detail']];
        }
        $output->table(['Result', 'Relationship', 'Kind', 'Detail'], $rows);
        $output->line();
        $output->line("{$counts['pass']} pass, {$counts['warn']} warning" . ($counts['warn'] === 1 ? '' : 's') . ", {$counts['broken']} broken.");
        if ($counts['broken'] > 0) {
            $output->error('Broken relationships: fix the rows (or the constraints) before relying on these tables. Nothing was changed.');
            return 1;
        }
        $output->info('No broken relationships. Run  php cast migrate:sync  to write the migrations (foreign keys are included).');
        return 0;
    }

    /** @return list<string> */
    private function names(mixed $value): array
    {
        return is_string($value) && $value !== '' ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
    }
}
