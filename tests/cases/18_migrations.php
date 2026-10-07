<?php

declare(strict_types=1);

use Cast\App\Application;
use Cast\Core\Config;
use Cast\Core\Database;
use Cast\Core\QueryBuilder;
use Cast\Database\Blueprint;
use Cast\Database\Grammars\{MySqlGrammar, PostgresGrammar, SqliteGrammar};
use Cast\Database\{MigrationException, Schema};

function orders_blueprint(): Blueprint
{
    $b = new Blueprint('orders', true);
    $b->id();
    $b->foreignId('user_id')->constrained()->cascadeOnDelete();
    $b->string('number', 30)->unique();
    $b->decimal('total', 12, 2)->default(0);
    $b->boolean('paid')->default(false);
    $b->enum('status', ['new', "it's"])->default('new');
    $b->text('note')->nullable()->comment('free text');
    $b->timestamp('placed_at')->useCurrent();
    $b->timestamps();
    $b->index(['user_id', 'status']);
    return $b;
}

/** A migration file's source, for tests. */
function migration_source(string $up, string $down): string
{
    return "<?php\nuse Cast\\Database\\{Blueprint, Migration, Schema};\nreturn new class extends Migration {\n"
        . "    public function up(Schema \$schema): void { $up }\n    public function down(Schema \$schema): void { $down }\n};\n";
}

function migrate_app(array $migrations = [], array $files = []): Application
{
    $all = $files;
    foreach ($migrations as $name => $source) $all["database/migrations/$name.php"] = $source;
    $app = boot_app($all);
    sqlite();
    return $app;
}

const CREATE_ITEMS = [
    '2026_01_01_000001_create_items_table',
    '$schema->create("items", function (Blueprint $t) { $t->id(); $t->string("name"); $t->integer("qty")->default(0); });',
    '$schema->dropIfExists("items");',
];
const CREATE_TAGS = [
    '2026_01_01_000002_create_tags_table',
    '$schema->create("tags", function (Blueprint $t) { $t->id(); $t->string("label")->unique(); });',
    '$schema->dropIfExists("tags");',
];

function two_migrations(): Application
{
    return migrate_app([
        CREATE_ITEMS[0] => migration_source(CREATE_ITEMS[1], CREATE_ITEMS[2]),
        CREATE_TAGS[0] => migration_source(CREATE_TAGS[1], CREATE_TAGS[2]),
    ]);
}

function tables(): array
{
    return (new Schema(Database::connection()))->tables();
}

// ---------------------------------------------------------------- grammars

test('Schema SQL: MySQL create table (types, defaults, enum, comment, FK, indexes)', function () {
    eq(<<<'SQL'
CREATE TABLE `orders` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `number` VARCHAR(30) NOT NULL,
    `total` DECIMAL(12, 2) NOT NULL DEFAULT 0,
    `paid` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('new', 'it''s') NOT NULL DEFAULT 'new',
    `note` TEXT NULL COMMENT 'free text',
    `placed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL, (new MySqlGrammar())->compileCreate(orders_blueprint())[0]);
    eq([
        'CREATE UNIQUE INDEX `orders_number_unique` ON `orders` (`number`)',
        'CREATE INDEX `orders_user_id_status_index` ON `orders` (`user_id`, `status`)',
    ], array_slice((new MySqlGrammar())->compileCreate(orders_blueprint()), 1));
});

test('Schema SQL: PostgreSQL create table (serial key, boolean, CHECK enum, comments after)', function () {
    $sql = (new PostgresGrammar())->compileCreate(orders_blueprint());
    has('"id" BIGSERIAL PRIMARY KEY', $sql[0]);
    has('"user_id" BIGINT NOT NULL', $sql[0]);
    has('"total" NUMERIC(12, 2) NOT NULL DEFAULT 0', $sql[0]);
    has('"paid" BOOLEAN NOT NULL DEFAULT FALSE', $sql[0]);
    has('"status" VARCHAR(255) NOT NULL DEFAULT \'new\' CHECK ("status" IN (\'new\', \'it\'\'s\'))', $sql[0]);
    has('"placed_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql[0]);
    lacks('COMMENT', $sql[0]);
    eq('COMMENT ON COLUMN "orders"."note" IS \'free text\'', end($sql));
});

test('Schema SQL: SQLite create table (autoincrement key, inline FK, no comments)', function () {
    $sql = (new SqliteGrammar())->compileCreate(orders_blueprint());
    has('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql[0]);
    has('"paid" INTEGER NOT NULL DEFAULT 0', $sql[0]);
    has('CONSTRAINT "orders_user_id_foreign" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE', $sql[0]);
    eq(3, count($sql), 'table + two indexes');
});

test('Schema SQL: alter statements per driver', function () {
    $alter = function () {
        $a = new Blueprint('orders', false);
        $a->string('ref', 40)->nullable()->after('number')->index();
        $a->dropColumn('note');
        $a->renameColumn('paid', 'is_paid');
        $a->dropIndex(['user_id', 'status']);
        return $a;
    };
    eq([
        'DROP INDEX `orders_user_id_status_index` ON `orders`',
        'ALTER TABLE `orders` RENAME COLUMN `paid` TO `is_paid`',
        'ALTER TABLE `orders` DROP COLUMN `note`',
        'ALTER TABLE `orders` ADD COLUMN `ref` VARCHAR(40) NULL AFTER `number`',
        'CREATE INDEX `orders_ref_index` ON `orders` (`ref`)',
    ], (new MySqlGrammar())->compileAlter($alter()));
    $pg = (new PostgresGrammar())->compileAlter($alter());
    eq('DROP INDEX "orders_user_id_status_index"', $pg[0]);
    eq('ALTER TABLE "orders" ADD COLUMN "ref" VARCHAR(40) NULL', $pg[3], 'AFTER is MySQL only');
});

test('Schema SQL: foreign keys, drop foreign, rename and drop tables, per driver', function () {
    $b = new Blueprint('posts', false);
    $b->foreign('user_id')->references('id')->on('users')->onDelete('set null')->onUpdate('cascade');
    eq(['ALTER TABLE `posts` ADD CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE'], (new MySqlGrammar())->compileAlter($b));

    $d = new Blueprint('posts', false);
    $d->dropForeign(['user_id']);
    eq(['ALTER TABLE `posts` DROP FOREIGN KEY `posts_user_id_foreign`'], (new MySqlGrammar())->compileAlter($d));
    eq(['ALTER TABLE "posts" DROP CONSTRAINT "posts_user_id_foreign"'], (new PostgresGrammar())->compileAlter($d));

    eq('RENAME TABLE `a` TO `b`', (new MySqlGrammar())->compileRename('a', 'b'));
    eq('ALTER TABLE "a" RENAME TO "b"', (new SqliteGrammar())->compileRename('a', 'b'));
    eq('DROP TABLE IF EXISTS `a`', (new MySqlGrammar())->compileDrop('a', true));
});

test('Schema SQL: SQLite refuses alterations it cannot do, with a clear message', function () {
    $g = new SqliteGrammar();
    $fk = new Blueprint('posts', false);
    $fk->foreign('user_id')->on('users');
    throws(MigrationException::class, fn() => $g->compileAlter($fk), 'foreign key');

    $notNull = new Blueprint('posts', false);
    $notNull->string('slug');
    throws(MigrationException::class, fn() => $g->compileAlter($notNull), 'default');
    eq(1, count($g->compileAlter((function () { $b = new Blueprint('posts', false); $b->string('slug')->default(''); return $b; })())));
});

test('Schema SQL: names and values cannot inject SQL', function () {
    $g = new MySqlGrammar();
    foreach (['a b', 'a;DROP', 'a`b', '1abc', '', 'a-b'] as $bad) {
        throws(InvalidArgumentException::class, fn() => $g->id($bad), 'identifier');
    }
    $b = new Blueprint('t', true);
    $b->string('x; DROP TABLE y');
    throws(InvalidArgumentException::class, fn() => $g->compileCreate($b));

    eq("'a''b'", $g->value("a'b"));
    eq("'a\\\\b'", $g->value('a\\b'), 'MySQL escapes backslashes');
    eq("'a\\b'", (new SqliteGrammar())->value('a\\b'));
    eq("'; DROP TABLE x; --'", $g->value("; DROP TABLE x; --"), 'quoted, so inert');
    eq('1', $g->value(true));
    eq('TRUE', (new PostgresGrammar())->value(true));
    eq('NULL', $g->value(null));
    eq('2.5', $g->value(2.5));
    throws(InvalidArgumentException::class, fn() => $g->value("a\0b"));
    throws(InvalidArgumentException::class, fn() => $g->value(NAN));

    throws(InvalidArgumentException::class, fn() => (new Cast\Database\ForeignKey(['a']))->onDelete('cascade; DROP TABLE x'), 'action');
});

// --------------------------------------------------------- execution (SQLite)

test('Schema: create, alter, index, foreign keys, rename and drop on a real database', function () {
    $pdo = sqlite();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $s = new Schema($pdo);

    $s->create('users', function (Blueprint $t) { $t->id(); $t->string('email', 120)->unique(); });
    $s->create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        $t->string('title');
        $t->enum('state', ['draft', 'live'])->default('draft');
        $t->timestamps();
        $t->index('title');
    });
    ok($s->hasTable('users') && $s->hasTable('posts') && !$s->hasTable('nope'));
    eq(['id', 'user_id', 'title', 'state', 'created_at', 'updated_at'], $s->columns('posts'));

    $pdo->exec("INSERT INTO users (email) VALUES ('a@b.co')");
    $pdo->exec("INSERT INTO posts (user_id, title) VALUES (1, 'First')");
    eq('draft', $pdo->query('SELECT state FROM posts')->fetchColumn(), 'default applied');

    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO posts (user_id, title) VALUES (99, 'Orphan')"), 'FOREIGN KEY');
    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO posts (user_id, title, state) VALUES (1, 'x', 'bogus')"), 'CHECK');
    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO users (email) VALUES ('a@b.co')"), 'UNIQUE');

    $s->table('posts', function (Blueprint $t) {
        $t->integer('views')->default(0);
        $t->string('slug', 80)->nullable()->index();
        $t->renameColumn('title', 'headline');
    });
    ok($s->hasColumn('posts', 'views') && $s->hasColumn('posts', 'slug') && $s->hasColumn('posts', 'headline') && !$s->hasColumn('posts', 'title'));

    $s->table('posts', function (Blueprint $t) { $t->dropIndex(['slug']); $t->dropColumn('slug'); $t->dropColumn('views'); });
    ok(!$s->hasColumn('posts', 'slug') && !$s->hasColumn('posts', 'views'));

    $pdo->exec('DELETE FROM users');
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn(), 'ON DELETE CASCADE');

    $s->rename('posts', 'articles');
    ok($s->hasTable('articles') && !$s->hasTable('posts'));
    $s->drop('articles');
    $s->dropIfExists('articles');
    $s->dropIfExists('users');
    eq([], $s->tables());
});

test('Schema: pretend collects the SQL and changes nothing; statement() runs raw SQL', function () {
    $pdo = sqlite();
    $s = new Schema($pdo);
    $sql = $s->pretend(function (Schema $s) {
        $s->create('x', fn(Blueprint $t) => $t->id());
        $s->statement('CREATE VIEW v AS SELECT 1');
    });
    eq(2, count($sql));
    has('CREATE TABLE "x"', $sql[0]);
    eq([], $s->tables());

    $s->statement('CREATE TABLE raw_t (a INTEGER)');
    $s->statement('INSERT INTO raw_t (a) VALUES (?)', [7]);
    eq(7, (int) $pdo->query('SELECT a FROM raw_t')->fetchColumn());
});

test('Schema: an unsupported database is a clear error', function () {
    throws(InvalidArgumentException::class, fn() => Schema::grammarFor('oci'), 'migrator');
});

// ------------------------------------------------------------------ runner

test('Migrator: migrate runs pending files in order and records a batch; running again does nothing', function () {
    $app = two_migrations();
    $m = $app->make('migrator');
    $lines = [];
    $m->onProgress(function (string $l) use (&$lines) { $lines[] = $l; });

    eq([CREATE_ITEMS[0], CREATE_TAGS[0]], $m->migrate());
    $have = array_diff(tables(), ['sqlite_sequence']);
    sort($have);
    eq(['items', 'migrations', 'tags'], array_values($have));
    ok(str_contains(implode("\n", $lines), 'Migrating: ' . CREATE_ITEMS[0]));
    ok(str_contains(implode("\n", $lines), 'Migrated:  ' . CREATE_TAGS[0]));

    $rows = QueryBuilder::table('migrations')->orderBy('id')->get();
    eq([1, 1], array_map(fn($r) => (int) $r['batch'], $rows), 'one batch');

    eq([], $m->migrate());
    eq([], $m->pretended());
});

test('Migrator: --step gives each migration its own batch; rollback undoes the last batch or N migrations', function () {
    $app = two_migrations();
    $m = $app->make('migrator');
    $m->migrate(['step' => true]);
    eq([1, 2], array_map(fn($r) => (int) $r['batch'], QueryBuilder::table('migrations')->orderBy('id')->get()));

    eq([CREATE_TAGS[0]], $m->rollback(), 'the last batch only');
    ok(!in_array('tags', tables(), true) && in_array('items', tables(), true));

    $m->migrate();
    eq([CREATE_TAGS[0], CREATE_ITEMS[0]], $m->rollback(2), 'the last two, newest first');
    ok(!in_array('items', tables(), true));
    eq([], $m->rollback(), 'nothing left');
});

test('Migrator: reset, refresh and fresh', function () {
    $app = two_migrations();
    $m = $app->make('migrator');
    $m->migrate();
    sqlite_insert('items', ['name' => 'keep me?']);

    eq([CREATE_TAGS[0], CREATE_ITEMS[0]], $m->reset());
    eq([], array_diff(tables(), ['migrations', 'sqlite_sequence']));

    $m->migrate();
    sqlite_insert('items', ['name' => 'gone after refresh']);
    eq([CREATE_ITEMS[0], CREATE_TAGS[0]], $m->refresh());
    eq(0, (int) Database::connection()->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'refresh rebuilt the tables');

    // fresh also drops tables no migration knows about
    Database::connection()->exec('CREATE TABLE stray (a INTEGER)');
    eq([CREATE_ITEMS[0], CREATE_TAGS[0]], $m->fresh());
    ok(!in_array('stray', tables(), true));
});

function sqlite_insert(string $table, array $row): void
{
    QueryBuilder::table($table)->insert($row);
}

test('Migrator: status lists every file with its batch', function () {
    $app = two_migrations();
    $m = $app->make('migrator');
    eq([['migration' => CREATE_ITEMS[0], 'ran' => false, 'batch' => null], ['migration' => CREATE_TAGS[0], 'ran' => false, 'batch' => null]], $m->status(), 'before the table exists');
    $m->rollback();   // creates the migrations table
    $m->migrate(['step' => true]);
    eq([['migration' => CREATE_ITEMS[0], 'ran' => true, 'batch' => 1], ['migration' => CREATE_TAGS[0], 'ran' => true, 'batch' => 2]], $m->status());

    unlink($app->databasePath('migrations/' . CREATE_TAGS[0] . '.php'));
    $names = array_column($m->status(), 'migration');
    ok(in_array(CREATE_TAGS[0], $names, true), 'a migration that ran but whose file is gone is still listed');
});

test('Migrator: pretend returns the SQL and records nothing', function () {
    $app = two_migrations();
    $m = $app->make('migrator');
    eq([CREATE_ITEMS[0], CREATE_TAGS[0]], $m->migrate(['pretend' => true]));
    $sql = $m->pretended();
    has('CREATE TABLE "items"', $sql[CREATE_ITEMS[0]][0]);
    ok(!in_array('items', tables(), true));
    eq(0, (int) Database::connection()->query('SELECT COUNT(*) FROM migrations')->fetchColumn());

    $m->migrate();
    $m->rollback(null, true);
    has('DROP TABLE IF EXISTS "tags"', $m->pretended()[CREATE_TAGS[0]][0]);
    ok(in_array('tags', tables(), true), 'a pretended rollback leaves the table');
});

test('Migrator: a failing migration is rolled back completely and reported by name', function () {
    $app = migrate_app([
        '2026_01_01_000001_create_a_table' => migration_source('$schema->create("a", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("a");'),
        '2026_01_01_000002_breaks' => migration_source('$schema->create("b", fn(Blueprint $t) => $t->id()); $schema->statement("THIS IS NOT SQL");', ''),
        '2026_01_01_000003_never_runs' => migration_source('$schema->create("c", fn(Blueprint $t) => $t->id());', ''),
    ]);
    $m = $app->make('migrator');
    $e = throws(MigrationException::class, fn() => $m->migrate(), '2026_01_01_000002_breaks');
    ok($e->getPrevious() instanceof PDOException);
    ok(in_array('a', tables(), true), 'earlier migrations stay applied');
    ok(!in_array('b', tables(), true), 'the failed one left nothing behind (transactional DDL)');
    ok(!in_array('c', tables(), true));
    eq(['2026_01_01_000001_create_a_table'], array_column(QueryBuilder::table('migrations')->get(), 'migration'));
});

test('Migrator: a file that does not return a Migration is an error', function () {
    $app = migrate_app(['2026_01_01_000001_bad' => "<?php\nreturn 5;\n"]);
    throws(MigrationException::class, fn() => $app->make('migrator')->migrate(), 'must return');
});

test('Migrator: make creates files named after the pattern, with the right stub', function () {
    $app = migrate_app();
    $m = $app->make('migrator');

    $create = $m->make('create_order_items_table');
    ok(is_file($create) && preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_order_items_table\.php$/', $create) === 1);
    has("\$schema->create('order_items'", file_get_contents($create));
    has("dropIfExists('order_items')", file_get_contents($create));

    $alter = $m->make('Add Status To Orders Table');
    ok(str_ends_with($alter, '_add_status_to_orders_table.php'));
    has("\$schema->table('orders'", file_get_contents($alter));

    $explicit = $m->make('anything', 'widgets');
    has("\$schema->create('widgets'", file_get_contents($explicit));
    $blank = $m->make('seed_defaults');
    has('//', file_get_contents($blank));
    lacks('$schema->create', file_get_contents($blank));

    throws(MigrationException::class, fn() => $m->make('create_order_items_table'), 'already exists');
    throws(MigrationException::class, fn() => $m->make('9bad'), 'start with a letter');
    throws(MigrationException::class, fn() => $m->make('x', "bad table"), 'valid table name');

    // the files it wrote really run
    $m->migrate();
    ok(in_array('order_items', tables(), true) && in_array('widgets', tables(), true));
    eq(['create_order_items_table'], array_map(fn($p) => substr($p, 18), array_column(QueryBuilder::table('migrations')->where('migration', 'LIKE', '%order_items%')->get(), 'migration')));
});

test('Migrator: a second runner cannot start while one holds the lock', function () {
    $app = two_migrations();
    mkdir_p($app->storagePath('framework'));
    $lock = fopen($app->storagePath('framework/migrate.lock'), 'c');
    flock($lock, LOCK_EX);
    throws(MigrationException::class, fn() => $app->make('migrator')->migrate(), 'Another migration');
    flock($lock, LOCK_UN);
    fclose($lock);
    eq(2, count($app->make('migrator')->migrate()));
});

function mkdir_p(string $dir): void
{
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

test('Migrator: custom migrations table and path from config', function () {
    $app = boot_app([
        'config/database.php' => "<?php return ['migrations' => ['table' => 'schema_log', 'path' => 'db/changes']];",
        'db/changes/2026_01_01_000001_create_z_table.php' => migration_source('$schema->create("z", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("z");'),
    ]);
    sqlite();
    $app->make('migrator')->migrate();
    ok(in_array('schema_log', tables(), true) && in_array('z', tables(), true) && !in_array('migrations', tables(), true));
});

test('Schema: columns() come in table order and tables() sorted, whatever the database returns them in', function () {
    $s = new Schema(sqlite());
    $s->create('zebra', function (Blueprint $t) { $t->id(); $t->string('zeta'); $t->string('alpha'); $t->string('mid'); });
    $s->create('apple', fn(Blueprint $t) => $t->id());
    eq(['id', 'zeta', 'alpha', 'mid'], $s->columns('zebra'), 'not alphabetical');
    eq(['apple', 'zebra'], array_values(array_diff($s->tables(), ['sqlite_sequence'])));
    foreach ([new MySqlGrammar(), new PostgresGrammar(), new SqliteGrammar()] as $g) {
        has('ORDER BY', $g->compileListTables(), $g->driver() . ' tables');
        has('ORDER BY', $g->compileListColumns('t')[0], $g->driver() . ' columns');
    }
});
