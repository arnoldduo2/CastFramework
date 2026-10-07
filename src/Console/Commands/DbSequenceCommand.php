<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Input, Output};
use Cast\Contracts\Migrator;
use Cast\Core\Database;
use Cast\Database\Schema;

/**
 * `php cast db:sequence` shows where each table's auto-increment counter stands and whether it is behind the data.
 *
 *   php cast db:sequence                  every table with an auto-increment column
 *   php cast db:sequence orders           one table
 *   php cast db:sequence orders --set=5000   make the next row get 5000
 *   php cast db:sequence --sync           move every counter that is behind to MAX(id)+1 (never lowers one that is ahead)
 */
final class DbSequenceCommand extends MigrationCommand
{
    protected string $name = 'db:sequence';
    protected string $description = 'Show or fix auto-increment positions: [table] [--set=N] [--sync] (--force in production)';

    protected function allowed(Input $input, Output $output): bool
    {
        // reading is always fine; changing needs the same production guard as the migrations
        return ($input->hasOption('set') || $input->hasOption('sync')) ? parent::allowed($input, $output) : true;
    }

    protected function execute(Migrator $migrator, Input $input, Output $output): int
    {
        $pdo = Database::connection();
        $schema = new Schema($pdo);
        $g = $schema->grammar();
        $only = $input->argument(0);
        $set = $input->option('set');

        if ($set !== null && $set !== false && (!is_string($set) || !ctype_digit($set) || (int) $set < 1)) {
            $output->error('--set needs a whole number of 1 or more, e.g. --set=5000');
            return 1;
        }
        if ($set !== null && $set !== false && ($only === null || $only === '')) {
            $output->error('--set needs a table: php cast db:sequence orders --set=5000');
            return 1;
        }

        $tables = $only !== null && $only !== '' ? [$only] : $schema->tables();
        if ($only && !in_array($only, $schema->tables(), true)) {
            $output->error("Table \"$only\" does not exist.");
            return 1;
        }

        $rows = [];
        $behind = 0;
        foreach ($tables as $table) {
            $column = $schema->autoIncrementColumn($table);
            if ($column === null) {
                if ($only) {
                    $output->error("Table \"$table\" has no auto-increment column.");
                    return 1;
                }
                continue;
            }
            $max = (int) $pdo->query('SELECT MAX(' . $g->id($column) . ') FROM ' . $g->id($table))->fetchColumn();
            $next = $schema->nextAutoIncrement($table) ?? 1;
            $action = '';
            if (is_string($set)) {
                $schema->autoIncrement($table, (int) $set);
                $next = $schema->nextAutoIncrement($table) ?? (int) $set;
                $action = 'set';
            } elseif ($next <= $max) {
                $behind++;
                if ($input->hasOption('sync')) {
                    $schema->autoIncrement($table, $max + 1);
                    $next = $schema->nextAutoIncrement($table) ?? $max + 1;
                    $action = 'fixed';
                }
            }
            $status = $action !== '' ? $action : ($next > $max ? 'ok' : 'BEHIND: the next insert would collide');
            $rows[] = [$table, $column, (string) $max, (string) $next, $status];
        }

        if (!$rows) {
            $output->line('No table has an auto-increment column.');
            return 0;
        }
        $output->table(['Table', 'Column', 'Highest', 'Next', 'Status'], $rows);
        if ($behind > 0 && !$input->hasOption('sync') && !is_string($set)) {
            $output->warn("$behind table" . ($behind === 1 ? ' is' : 's are') . ' behind its data. Run  php cast db:sequence --sync  to fix it.');
            return 1;
        }
        return 0;
    }
}
