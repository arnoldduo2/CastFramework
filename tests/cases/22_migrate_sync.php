<?php

declare(strict_types=1);

use Cast\Core\Database;
use Cast\Database\Reverse\Reader;
use Cast\Database\{Blueprint, Schema};

/** The structure of every table except the migrations table, as the reader sees it (the thing a round trip must keep). */
function structure(PDO $pdo): array
{
    $schema = new Schema($pdo);
    $reader = new Reader($pdo, $schema->grammar());
    $out = [];
    foreach ($reader->tables() as $t) {
        if ($t === 'migrations') continue;
        $def = $reader->read($t);
        unset($def['notes']);
        foreach ($def['indexes'] as &$i) $i['name'] = null;   // the names of unnamed indexes differ between databases
        foreach ($def['foreign'] as &$f) $f['name'] = null;
        unset($i, $f);
        usort($def['indexes'], fn($a, $b) => implode(',', $a['columns']) <=> implode(',', $b['columns']));
        $out[$t] = $def;
    }
    return $out;
}

/**
 * Legacy tables -> migrate:sync -> drop everything -> migrate -> the same structure.
 * @param list<string> $legacySql statements that create the legacy database
 */
function sync_roundtrip(PDO $pdo, array $legacySql, $app): array
{
    foreach ($legacySql as $sql) $pdo->exec($sql);
    $before = structure($pdo);

    [$code, $out] = cast(['migrate:sync'], $app);
    eq(0, $code, $out);
    // the tables exist, so the files are recorded as run and migrate has nothing to do
    has('Nothing to migrate.', cast(['migrate'], $app)[1]);

    // a fresh database from the files alone
    (new Schema($pdo))->dropAllTables();
    [$code, $out] = cast(['migrate'], $app);
    eq(0, $code, $out);
    eq($before, structure($pdo), 'the migrations rebuild the same structure');
    return [$before, $out];
}

test('migrate:sync (sqlite): legacy tables become migrations that rebuild the same structure', function () {
    $app = console_app();
    $pdo = sqlite();
    $files = [
        'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(120) NOT NULL UNIQUE, name VARCHAR(80), balance NUMERIC(12, 2) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, note TEXT)',
        "CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL REFERENCES customers (id) ON DELETE CASCADE, ref VARCHAR(30) NOT NULL DEFAULT 'it''s N/A', total NUMERIC(12, 2))",
        'CREATE INDEX orders_ref_idx ON orders (ref)',
        'CREATE TABLE order_items (order_id INTEGER NOT NULL, line INTEGER NOT NULL, sku VARCHAR(40), PRIMARY KEY (order_id, line), FOREIGN KEY (order_id) REFERENCES orders (id))',
        'CREATE UNIQUE INDEX items_sku ON order_items (sku, line)',
    ];
    [$before] = sync_roundtrip($pdo, $files, $app);
    eq(['customers', 'order_items', 'orders'], array_keys($before));

    $dir = $app->basePath('database/migrations');
    $names = array_map('basename', glob("$dir/*.php"));
    eq(3, count($names));
    // referenced tables are created first
    $order = implode(',', $names);
    ok(strpos($order, 'create_customers_table') < strpos($order, 'create_orders_table'), 'customers before orders');
    ok(strpos($order, 'create_orders_table') < strpos($order, 'create_order_items_table'), 'orders before order_items');
    $orders = (string) file_get_contents(glob("$dir/*create_orders_table.php")[0]);
    has("->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade')", $orders);
    has("->default('it\\'s N/A')", $orders);
    $customers = (string) file_get_contents(glob("$dir/*create_customers_table.php")[0]);
    has("\$table->id('id');", $customers);
    has("\$table->string('email', 120)", $customers);
    has("$table->unique('email')", $customers);
    has("->useCurrent()", $customers);
});

test('migrate:sync: one table, --except, --pretend, --no-record, tables that already have a migration', function () {
    $app = console_app();
    $pdo = sqlite();
    $pdo->exec('CREATE TABLE a (id INTEGER PRIMARY KEY AUTOINCREMENT, n VARCHAR(10))');
    $pdo->exec('CREATE TABLE b (id INTEGER PRIMARY KEY AUTOINCREMENT, a_id INTEGER REFERENCES a (id))');
    $pdo->exec('CREATE TABLE c (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    $dir = $app->basePath('database/migrations');

    [$code, $out] = cast(['migrate:sync', '--pretend'], $app);
    eq(0, $code, $out);
    has('Would write', $out);
    has("\$schema->create('b'", $out);
    ok(!is_dir($dir) || !glob("$dir/*.php"), 'pretend writes nothing');

    [$code, $out] = cast(['migrate:sync', 'a'], $app);
    eq(0, $code, $out);
    eq(1, count(glob("$dir/*.php")));
    has('Written', $out);

    [$code, $out] = cast(['migrate:sync', '--table=a,c'], $app);
    has('Skipped  a: already created by', $out);
    eq(2, count(glob("$dir/*.php")));

    [$code, $out] = cast(['migrate:sync', '--except=b', '--no-record'], $app);
    eq(0, $code, $out);
    has('Nothing new', $out);
    eq(2, count(glob("$dir/*.php")), 'a and c are covered, b is excluded: nothing new');

    [$code, $out] = cast(['migrate:sync', 'nope'], $app);
    eq(1, $code);
    has('does not exist', $out);

    [$code, $out] = cast(['migrate:sync', 'migrations'], $app);
    has('records migrations', $out);

    // b only, not recorded: status shows it pending, baseline records it
    [$code, $out] = cast(['migrate:sync', 'b', '--no-record'], $app);
    eq(0, $code, $out);
    has('No', cast(['migrate:status'], $app)[1]);
    has('1 migration recorded', cast(['migrate:baseline'], $app)[1]);
    has('Nothing to migrate.', cast(['migrate'], $app)[1]);
});

test('migrate:sync: unknown column types are written as the closest match with a note', function () {
    $app = console_app();
    $pdo = sqlite();
    $pdo->exec('CREATE TABLE odd (id INTEGER PRIMARY KEY AUTOINCREMENT, shape GEOMETRY, born YEAR, label)');
    [$code, $out] = cast(['migrate:sync'], $app);
    eq(0, $code, $out);
    has('note:', $out);
    has('geometry', $out);
    $file = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*.php')[0]);
    has('// NOTE: column "shape"', $file);
    has("\$table->text('shape')", $file);
    has("\$table->smallInteger('born')", $file);
});

test('migrate:sync: production needs --force, and another migrator is refused', function () {
    $app = console_app([], 'production');
    sqlite()->exec('CREATE TABLE a (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    [$code, $out] = cast(['migrate:sync'], $app);
    eq(1, $code);
    has('--force', $out);
    eq(0, cast(['migrate:sync', '--force'], $app)[0]);

    $app = console_app();
    $app->singleton('migrator', fn() => new FakeOrmMigrator());
    [$code, $out] = cast(['migrate:sync'], $app);
    eq(1, $code);
    has('built-in migrator', $out);
});

test('migrate:sync --init: tests every relationship and reports pass, warn and broken', function () {
    $app = console_app();
    $pdo = sqlite();
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(50))');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL REFERENCES customers (id))');
    $pdo->exec('CREATE INDEX orders_customer ON orders (customer_id)');
    $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, order_id INTEGER)');
    $pdo->exec("INSERT INTO customers (name) VALUES ('a'), ('b')");
    $pdo->exec('INSERT INTO orders (customer_id) VALUES (1), (2)');
    $pdo->exec('INSERT INTO invoices (customer_id, order_id) VALUES (1, 1), (2, 2)');

    [$code, $out] = cast(['migrate:sync', '--init'], $app);
    eq(0, $code, $out);
    has('PASS', $out);
    has('orders.customer_id -> customers(id)', $out);
    has('WARN', $out);
    has('invoices.customer_id -> customers(id)', $out);
    has('has no foreign key', $out);
    has('0 broken', $out);
    ok(!is_dir($app->basePath('database/migrations')) || !glob($app->basePath('database/migrations') . '/*.php'), 'the audit writes nothing');

    // orphans: SQLite does not enforce foreign keys unless asked, so legacy data can be wrong
    $pdo->exec('INSERT INTO orders (customer_id) VALUES (99)');
    $pdo->exec('INSERT INTO invoices (customer_id, order_id) VALUES (1, 404)');
    [$code, $out] = cast(['migrate:sync', '--init'], $app);
    eq(1, $code, $out);
    has('BROKEN', $out);
    has('1 row point to nothing (e.g. 99)', str_replace('rows', 'row', $out));
    has('invoices.order_id -> orders(id)', $out);
    has('(e.g. 404)', $out);
    has('2 broken', $out);

    // one table only
    [$code, $out] = cast(['migrate:sync', 'customers', '--init'], $app);
    eq(0, $code, $out);
    has('No relationships found', $out);
});

test('migrate:sync: foreign keys are written, ordered, and a missing target is flagged', function () {
    $app = console_app();
    $pdo = sqlite();
    $pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    $pdo->exec('CREATE TABLE kids (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER REFERENCES parents (id) ON DELETE SET NULL, boss_id INTEGER REFERENCES kids (id))');
    [$code, $out] = cast(['migrate:sync', 'kids'], $app);
    eq(0, $code, $out);
    has('which has no migration yet', $out);
    $file = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*create_kids_table.php')[0]);
    has("->references('id')->on('parents')->onDelete('set null')", $file);
    has("->references('id')->on('kids')", $file);
});

test('migrate:sync --auto-increment and db:sequence: the counter position is carried and can be fixed', function () {
    $app = console_app();
    $pdo = sqlite();
    $pdo->exec('CREATE TABLE tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, t VARCHAR(5))');
    $pdo->exec('INSERT INTO tickets (id, t) VALUES (4999, "x")');
    $schema = new Schema($pdo);
    eq(5000, $schema->nextAutoIncrement('tickets'));

    cast(['migrate:sync', '--auto-increment'], $app);
    $file = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*create_tickets_table.php')[0]);
    has("\$schema->autoIncrement('tickets', 5000);", $file);

    // rebuilt from the migration alone, numbering continues where it stopped
    $schema->dropAllTables();
    cast(['migrate:fresh'], $app);
    eq(5000, $schema->nextAutoIncrement('tickets'));
    $pdo->exec("INSERT INTO tickets (t) VALUES ('y')");
    eq('5000', (string) $pdo->query('SELECT MAX(id) FROM tickets')->fetchColumn());

    [$code, $out] = cast(['db:sequence'], $app);
    eq(0, $code, $out);
    has('tickets', $out);
    has('ok', $out);

    $schema->autoIncrement('tickets', 10);
    [$code, $out] = cast(['db:sequence'], $app);
    eq(1, $code, $out);
    has('BEHIND', $out);
    [$code, $out] = cast(['db:sequence', '--sync'], $app);
    eq(0, $code, $out);
    has('fixed', $out);
    eq(5001, $schema->nextAutoIncrement('tickets'));

    [$code, $out] = cast(['db:sequence', 'tickets', '--set=9000'], $app);
    eq(0, $code, $out);
    eq(9000, $schema->nextAutoIncrement('tickets'));
    eq(1, cast(['db:sequence', '--set=5'], $app)[0], 'needs a table');
    eq(1, cast(['db:sequence', 'tickets', '--set=abc'], $app)[0]);
    eq(1, cast(['db:sequence', 'nope'], $app)[0]);
});

test('collations: table and column collation in MySQL and PostgreSQL SQL', function () {
    $table = function (string $driver, callable $fn): string {
        $s = new Schema(sqlite(), Schema::grammarFor($driver));
        return implode(";\n", $s->pretend(function (Schema $s) use ($fn) { $s->create('t', $fn); }));
    };
    $sql = $table('mysql', function (Blueprint $t) { $t->charset('latin1'); $t->id(); $t->string('name', 20)->collation('latin1_bin'); });
    has('DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci', $sql);
    has('`name` VARCHAR(20) COLLATE latin1_bin NOT NULL', $sql);
    $sql = $table('mysql', function (Blueprint $t) { $t->collation('utf8mb4_bin'); $t->id(); });
    has('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin', $sql);
    has('DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $table('mysql', function (Blueprint $t) { $t->id(); }));
    has('"name" VARCHAR(20) COLLATE "C" NOT NULL', $table('pgsql', function (Blueprint $t) { $t->id(); $t->string('name', 20)->collation('C'); }));
    throws(InvalidArgumentException::class, fn() => (new Blueprint('t', true))->collation('x; DROP TABLE y'));
});

// ------------------------------------------------------------ real servers

$syncDriver = getenv('CAST_TEST_DB') ?: '';
if (in_array($syncDriver, ['mysql', 'pgsql'], true)) {
    test("[$syncDriver] migrate:sync: a legacy database rebuilds with the same structure", function () use ($syncDriver) {
        $app = console_app();
        $pdo = server_pdo();
        $mysql = $syncDriver === 'mysql';
        $q = fn(string $n) => $mysql ? "`$n`" : "\"$n\"";
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'SERIAL PRIMARY KEY';
        $legacy = $mysql ? [
            "CREATE TABLE customers (id $id, email VARCHAR(120) NOT NULL, name VARCHAR(80) NULL, balance DECIMAL(12,2) NOT NULL DEFAULT 0.00, active TINYINT(1) NOT NULL DEFAULT 1,
              kind ENUM('retail','trade') NOT NULL DEFAULT 'retail', meta JSON NULL, notes MEDIUMTEXT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_email (email)) ENGINE=InnoDB",
            "CREATE TABLE orders (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL, ref VARCHAR(30) NOT NULL DEFAULT 'N/A', total DECIMAL(12,2) NULL,
              opened DATE NULL, KEY orders_ref_idx (ref), CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB",
            "CREATE TABLE order_items (order_id BIGINT UNSIGNED NOT NULL, line SMALLINT NOT NULL, sku VARCHAR(40) NULL, qty INT NOT NULL DEFAULT 1, PRIMARY KEY (order_id, line),
              UNIQUE KEY items_sku (sku, line), CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders (id)) ENGINE=InnoDB",
        ] : [
            "CREATE TABLE customers (id $id, email VARCHAR(120) NOT NULL UNIQUE, name VARCHAR(80), balance NUMERIC(12,2) NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE,
              meta JSONB, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE orders (id BIGSERIAL PRIMARY KEY, customer_id INTEGER NOT NULL, ref VARCHAR(30) NOT NULL DEFAULT 'N/A', total NUMERIC(12,2), opened DATE,
              CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE ON UPDATE CASCADE)",
            'CREATE INDEX orders_ref_idx ON orders (ref)',
            "CREATE TABLE order_items (order_id BIGINT NOT NULL, line SMALLINT NOT NULL, sku VARCHAR(40), qty INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (order_id, line),
              CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders (id))",
            'CREATE UNIQUE INDEX items_sku ON order_items (sku, line)',
        ];
        [$before] = sync_roundtrip($pdo, $legacy, $app);
        eq(['customers', 'order_items', 'orders'], array_keys($before));
        $orders = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*create_orders_table.php')[0]);
        has("->onDelete('cascade')->onUpdate('cascade')", $orders);
        has("'fk_orders_customer'", $orders);
        (new Schema($pdo))->dropAllTables();
    });
}

if (in_array($syncDriver, ['mysql', 'pgsql'], true)) {
    test("[$syncDriver] migrate:sync: collations, auto-increment position and the relationship audit on a real server", function () use ($syncDriver) {
        $app = console_app();
        $pdo = server_pdo();
        $mysql = $syncDriver === 'mysql';
        if ($mysql) {
            $pdo->exec("CREATE TABLE notes (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(40) NOT NULL, body VARCHAR(40) COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");
            $pdo->exec('CREATE TABLE tags (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, note_id INT NOT NULL) ENGINE=InnoDB');
        } else {
            $pdo->exec('CREATE TABLE notes (id SERIAL PRIMARY KEY, title VARCHAR(40) NOT NULL, body VARCHAR(40) COLLATE "C")');
            $pdo->exec('CREATE TABLE tags (id SERIAL PRIMARY KEY, note_id INTEGER NOT NULL)');
        }
        $pdo->exec("INSERT INTO notes (id, title) VALUES (700, 'x')");
        if (!$mysql) $pdo->exec("SELECT setval(pg_get_serial_sequence('notes', 'id'), 700)");   // PostgreSQL does not move its sequence for an explicit id
        $pdo->exec('INSERT INTO tags (note_id) VALUES (700), (701)');
        $schema = new Schema($pdo);
        eq(701, $schema->nextAutoIncrement('notes'));

        // the audit finds the tag that points to a note that is not there, and says the types (here equal) allow a constraint
        [$code, $out] = cast(['migrate:sync', '--init'], $app);
        eq(1, $code, $out);
        has('BROKEN', $out);
        has('tags.note_id -> notes(id)', $out);
        has('(e.g. 701)', $out);

        $pdo->exec('DELETE FROM tags WHERE note_id = 701');
        eq(0, cast(['migrate:sync', '--init'], $app)[0]);

        $before = structure($pdo);
        cast(['migrate:sync', '--auto-increment'], $app);
        $notes = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*create_notes_table.php')[0]);
        has("\$schema->autoIncrement('notes', 701);", $notes);
        if ($mysql) {
            has("\$table->charset('latin1');", $notes);
            has("->collation('utf8mb4_bin')", $notes);
        } else {
            has("->collation('C')", $notes);
        }
        $schema->dropAllTables();
        eq(0, cast(['migrate:fresh'], $app)[0]);
        eq($before, structure($pdo), 'collations survive the round trip');
        eq(701, $schema->nextAutoIncrement('notes'), 'the position survives too');

        // db:sequence: behind the data, fixed, and a collation override on a second sync
        $pdo->exec("INSERT INTO notes (id, title) VALUES (900, 'y')");
        [$code, $out] = cast(['db:sequence', 'notes'], $app);
        if ($mysql) {
            eq(0, $code, $out);   // InnoDB moves its counter past an explicit id by itself
        } else {
            eq(1, $code, $out);   // a PostgreSQL sequence does not: the next insert would collide
            has('BEHIND', $out);
            eq(0, cast(['db:sequence', '--sync'], $app)[0]);
        }
        eq(901, $schema->nextAutoIncrement('notes'));

        if ($mysql) {
            (new Schema($pdo))->dropAllTables();
            $pdo->exec('CREATE TABLE a (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, n VARCHAR(10) COLLATE latin1_bin) DEFAULT CHARSET=latin1');
            exec('rm -f ' . escapeshellarg($app->basePath('database/migrations')) . '/*.php');
            cast(['migrate:sync', 'a', '--collation=utf8mb4_unicode_ci', '--no-record'], $app);
            $a = (string) file_get_contents(glob($app->basePath('database/migrations') . '/*create_a_table.php')[0]);
            lacks('latin1', $a);
            lacks('->collation(', $a);
        }
        (new Schema($pdo))->dropAllTables();
    });
}
