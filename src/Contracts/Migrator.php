<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * What `php cast migrate` and friends call. The built-in implementation is {@see \Cast\Database\Migrator}; an app that
 * uses another ORM or migration tool (Doctrine Migrations, Phinx, Eloquent...) binds its own adapter as `migrator`
 * in a service provider, and the console commands keep working unchanged.
 */
interface Migrator
{
    /**
     * Run the pending migrations.
     * @param array{step?: bool, pretend?: bool} $options `step`: one batch per migration; `pretend`: collect the SQL, change nothing
     * @return list<string> the names of the migrations that ran (or would run); with `pretend`, SQL statements are added as `name => [sql...]` in {@see pretended()}
     */
    public function migrate(array $options = []): array;

    /**
     * Undo migrations: the last batch, or with `$step` the last N migrations.
     * @return list<string> the names rolled back
     */
    public function rollback(?int $step = null, bool $pretend = false): array;

    /** Undo every migration. @return list<string> */
    public function reset(): array;

    /** Undo everything, then migrate again. @return list<string> the migrations that ran */
    public function refresh(): array;

    /** Drop every table, then migrate. @return list<string> */
    public function fresh(): array;

    /** @return list<array{migration: string, ran: bool, batch: ?int}> */
    public function status(): array;

    /** Create a migration file and return its path. */
    public function make(string $name, ?string $create = null, ?string $table = null): string;

    /** The SQL collected by the last `pretend` run: migration name => statements. @return array<string, list<string>> */
    public function pretended(): array;

    /** Called with a line of progress text for each step (the console prints it). */
    public function onProgress(?callable $listener): void;
}
