<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\App\ServiceProvider;
use Cast\Contracts\Migrator as MigratorContract;
use Cast\Core\Config;
use Cast\Core\Database;
use Cast\Core\QueryBuilder;

/** A migrator from "another ORM": the console commands must call it instead of the built-in one. */
final class FakeOrmMigrator implements MigratorContract
{
    public static array $calls = [];
    private $listener = null;
    public function migrate(array $options = []): array { self::$calls[] = ['migrate', $options]; $this->say('orm: migrating'); return ['2026_x_orm_one']; }
    public function rollback(?int $step = null, bool $pretend = false): array { self::$calls[] = ['rollback', $step, $pretend]; return ['2026_x_orm_one']; }
    public function reset(): array { self::$calls[] = ['reset']; return []; }
    public function refresh(): array { self::$calls[] = ['refresh']; return ['a', 'b']; }
    public function fresh(): array { self::$calls[] = ['fresh']; return ['a']; }
    public function status(): array { return [['migration' => 'orm_migration_1', 'ran' => true, 'batch' => 3]]; }
    public function make(string $name, ?string $create = null, ?string $table = null): string { self::$calls[] = ['make', $name, $create, $table]; return '/orm/' . $name . '.php'; }
    public function pretended(): array { return ['orm_pending' => ['SELECT 1']]; }
    public function onProgress(?callable $listener): void { $this->listener = $listener; }
    private function say(string $l): void { if ($this->listener) ($this->listener)($l); }
}

final class FakeOrmProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('migrator', fn() => new FakeOrmMigrator());
    }
}

function console_app(array $files = [], string $env = 'development'): Application
{
    $app = boot_app($files + ['.env' => "APP_ENV=$env\nAPP_DEBUG=false\n"]);
    sqlite();
    return $app;
}

test('migrate commands: make:migration, migrate, migrate:status, rollback, reset, refresh, fresh', function () {
    $app = console_app();
    [$code, $out] = cast(['make:migration', 'create_notes_table'], $app);
    eq(0, $code, $out);
    has('database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR, $out);
    has('_create_notes_table.php', $out);

    [$code, $out] = cast(['migrate:status'], $app);
    eq(0, $code);
    has('No', $out);
    has('create_notes_table', $out);

    [$code, $out] = cast(['migrate'], $app);
    eq(0, $code, $out);
    has('Migrating:', $out);
    has('1 migration ran.', $out);
    ok(in_array('notes', tables(), true));
    has('Nothing to migrate.', cast(['migrate'], $app)[1]);
    has('Yes', cast(['migrate:status'], $app)[1]);

    [$code, $out] = cast(['migrate:rollback'], $app);
    eq(0, $code);
    has('1 migration rolled back.', $out);
    ok(!in_array('notes', tables(), true));
    has('Nothing to roll back.', cast(['migrate:rollback'], $app)[1]);

    cast(['migrate'], $app);
    has('1 migration rolled back.', cast(['migrate:reset'], $app)[1]);
    has('1 migration ran.', cast(['migrate:refresh'], $app)[1]);
    Database::connection()->exec('CREATE TABLE junk (a INTEGER)');
    has('1 migration ran.', cast(['migrate:fresh'], $app)[1]);
    ok(!in_array('junk', tables(), true));

    eq(1, cast(['make:migration'], $app)[0], 'needs a name');
    [$code, $out] = cast(['make:migration', 'create_notes_table'], $app);
    eq(1, $code);
    has('already exists', $out);
});

test('migrate commands: --pretend prints the SQL, --step and --step=N', function () {
    $app = console_app(['database/migrations/2026_01_01_000001_create_a_table.php' => migration_source('$schema->create("a", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("a");'),
        'database/migrations/2026_01_01_000002_create_b_table.php' => migration_source('$schema->create("b", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("b");')]);
    [$code, $out] = cast(['migrate', '--pretend'], $app);
    eq(0, $code);
    has('-- 2026_01_01_000001_create_a_table', $out);
    has('CREATE TABLE "a"', $out);
    ok(!in_array('a', tables(), true));

    cast(['migrate', '--step'], $app);
    eq([1, 2], array_map(fn($r) => (int) $r['batch'], QueryBuilder::table('migrations')->orderBy('id')->get()));
    $out = cast(['migrate:rollback', '--step=2'], $app)[1];
    has('2 migrations rolled back.', $out);
});

test('migrate commands: in production they need --force (status, make and --pretend do not)', function () {
    $app = console_app(['database/migrations/2026_01_01_000001_create_a_table.php' => migration_source('$schema->create("a", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("a");')], 'production');
    foreach (['migrate', 'migrate:rollback', 'migrate:reset', 'migrate:refresh', 'migrate:fresh'] as $command) {
        [$code, $out] = cast([$command], $app);
        eq(1, $code, $command);
        has('--force', $out);
    }
    ok(!in_array('a', tables(), true), 'nothing ran');

    eq(0, cast(['migrate:status'], $app)[0]);
    eq(0, cast(['migrate', '--pretend'], $app)[0]);
    eq(0, cast(['make:migration', 'create_x_table'], $app)[0]);
    eq(0, cast(['migrate', '--force'], $app)[0]);
    ok(in_array('a', tables(), true));
});

test('seeders: make:seeder, db:seed, --class and migrate --seed', function () {
    $app = console_app();
    [$code, $out] = cast(['make:seeder', 'ConsoleNotesSeeder'], $app);
    eq(0, $code, $out);
    $file = $app->databasePath('seeders/ConsoleNotesSeeder.php');
    ok(is_file($file));
    eq(1, cast(['make:seeder', 'ConsoleNotesSeeder'], $app)[0], 'no overwrite');
    eq(1, cast(['make:seeder'], $app)[0]);

    file_put_contents($file, <<<'PHP'
<?php
namespace Database\Seeders;
use Cast\Database\Seeder;
use Cast\Core\QueryBuilder;
class ConsoleNotesSeeder extends Seeder
{
    public function run(): void { QueryBuilder::table('seeded')->insert(['label' => 'one']); }
}
PHP);
    file_put_contents($app->databasePath('seeders/ConsoleDatabaseSeeder.php'), <<<'PHP'
<?php
namespace Database\Seeders;
use Cast\Database\Seeder;
class ConsoleDatabaseSeeder extends Seeder
{
    public function run(): void { $this->call('ConsoleNotesSeeder'); $this->call(ConsoleNotesSeeder::class); }
}
PHP);
    Database::connection()->exec('CREATE TABLE seeded (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');

    [$code, $out] = cast(['db:seed', '--class=ConsoleDatabaseSeeder'], $app);
    eq(0, $code, $out);
    has('Seeded: Database\\Seeders\\ConsoleDatabaseSeeder', $out);
    eq(2, QueryBuilder::table('seeded')->count(), 'called twice');

    [$code, $out] = cast(['db:seed', '--class=Missing'], $app);
    eq(1, $code);
    has('was not found', $out);
});

test('migrate --seed runs the configured seeder (default DatabaseSeeder) after migrating', function () {
    $app = console_app(['database/migrations/2026_01_01_000001_create_flags_table.php' => migration_source('$schema->create("flags", fn(Blueprint $t) => $t->string("k"));', '$schema->dropIfExists("flags");'),
        'config/database.php' => "<?php return ['seeder' => 'FlagsSeeder'];",
        'database/seeders/FlagsSeeder.php' => "<?php\nnamespace Database\\Seeders;\nuse Cast\\Database\\Seeder;\nclass FlagsSeeder extends Seeder { public function run(): void { \\Cast\\Core\\QueryBuilder::table('flags')->insert(['k' => 'seeded']); } }\n"]);
    [$code, $out] = cast(['migrate', '--seed'], $app);
    eq(0, $code, $out);
    has('Seeding database', $out);
    eq('seeded', QueryBuilder::table('flags')->first()['k']);
});

test('another ORM: a bound migrator is what every migrate command calls', function () {
    FakeOrmMigrator::$calls = [];
    $app = console_app(['config/app.php' => '<?php return ["providers" => [' . FakeOrmProvider::class . '::class]];']);
    ok($app->make('migrator') instanceof FakeOrmMigrator, 'the app provider replaced the built-in one');

    [$code, $out] = cast(['migrate'], $app);
    eq(0, $code, $out);
    has('orm: migrating', $out);
    has('1 migration ran.', $out);
    [, $out] = cast(['migrate:status'], $app);
    has('orm_migration_1', $out);
    cast(['migrate:rollback', '--step=3'], $app);
    cast(['migrate:refresh'], $app);
    cast(['migrate:fresh'], $app);
    cast(['migrate:reset'], $app);
    [, $out] = cast(['make:migration', 'create_things_table', '--create=things'], $app);
    has('/orm/create_things_table.php', $out);
    [, $out] = cast(['migrate', '--pretend'], $app);
    has('-- orm_pending', $out);
    has('SELECT 1;', $out);

    eq([['migrate', ['step' => false, 'pretend' => false]], ['rollback', 3, false], ['refresh'], ['fresh'], ['reset'], ['make', 'create_things_table', 'things', null], ['migrate', ['step' => false, 'pretend' => true]]], FakeOrmMigrator::$calls);
});

test('another ORM: config database.connection lets it own the PDO that the framework uses', function () {
    $app = console_app();
    $theirs = new PDO('sqlite::memory:');
    $theirs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $theirs->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $theirs->exec('CREATE TABLE marker (a INTEGER)');

    Database::reset();
    Config::set('database.connection', fn() => $theirs);
    ok(Database::connection() === $theirs, 'the resolver supplies the connection');
    $theirs->exec('INSERT INTO marker (a) VALUES (5)');
    eq(5, (int) QueryBuilder::table('marker')->first()['a'], 'the query builder uses it');

    // so do migrations
    mkdir($app->databasePath('migrations'), 0777, true);
    file_put_contents($app->databasePath('migrations/2026_01_01_000001_create_mine_table.php'), migration_source('$schema->create("mine", fn(Blueprint $t) => $t->id());', ''));
    $app->make('migrator')->migrate();
    ok(in_array('mine', (new Cast\Database\Schema($theirs))->tables(), true), 'created on their connection');

    Database::reset();
    Config::set('database.connection', fn() => 'not a pdo');
    throws(InvalidArgumentException::class, fn() => Database::connection(), 'must return a PDO');
});
