<?php

declare(strict_types=1);

use Cast\Contracts\ModelContract;
use Cast\Core\Database;
use Cast\Core\Model;
use Cast\Core\QueryBuilder;

class JournalEntries extends Model {}
class Coa extends Model {}
class Special extends Model { protected static ?string $table = 'special_table'; }
class Items extends Model {}

function items_db(): PDO
{
    return sqlite("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER, active INTEGER DEFAULT 1, note TEXT);
        INSERT INTO items (name, qty, active, note) VALUES ('bolt', 10, 1, NULL), ('nut', 5, 1, 'x'), ('screw', 10, 0, NULL), ('washer', 0, 1, 'y');");
}

test('QueryBuilder: insert returns the new id; get/first/fetch read rows back', function () {
    items_db();
    $id = QueryBuilder::table('items')->insert(['name' => 'rivet', 'qty' => 3]);
    eq(5, $id);
    eq('rivet', QueryBuilder::table('items')->where('id', $id)->first()['name']);
    eq(false, QueryBuilder::table('items')->where('id', 999)->first());
    eq(4, count(QueryBuilder::table('items')->where('active', 1)->get()));
    eq('bolt', QueryBuilder::table('items')->orderBy('id')->fetch()['name']);
    eq([], QueryBuilder::table('items')->where('id', 999)->fetchAll());
    eq(3, QueryBuilder::table('items')->where('qty', '!=', 10)->count(), 'nut, washer and the new rivet');
});

test('QueryBuilder: where operators, and/or, NULL handling, whereIn', function () {
    items_db();
    $names = fn(QueryBuilder $q) => array_column($q->orderBy('id')->get(), 'name');
    eq(['bolt', 'screw'], $names(QueryBuilder::table('items')->where('qty', 10)));
    eq(['nut', 'washer'], $names(QueryBuilder::table('items')->where('qty', '<', 10)));
    eq(['bolt', 'nut', 'screw'], $names(QueryBuilder::table('items')->where('qty', '>=', 5)));
    eq(['bolt'], $names(QueryBuilder::table('items')->where('qty', 10)->and('active', 1)));
    eq(['bolt', 'nut'], $names(QueryBuilder::table('items')->where('name', 'bolt')->or('name', 'nut')));
    eq(['nut', 'washer'], $names(QueryBuilder::table('items')->where('note', '!=', null)), '!= null => IS NOT NULL');
    eq(['bolt', 'screw'], $names(QueryBuilder::table('items')->where('note', null)), '= null => IS NULL');
    eq(['bolt'], $names(QueryBuilder::table('items')->where('name', 'LIKE', '%o%')->and('name', 'NOT LIKE', 's%')));
    eq(['bolt', 'washer'], $names(QueryBuilder::table('items')->whereIn('name', ['bolt', 'washer', 'ghost'])));
    eq([], $names(QueryBuilder::table('items')->whereIn('name', [])), 'empty list matches nothing');
});

test('QueryBuilder: orderBy (several), limit/offset, select, count, exists', function () {
    items_db();
    eq(['bolt', 'screw', 'nut', 'washer'], array_column(QueryBuilder::table('items')->orderBy('qty', 'desc')->orderBy('id')->get(), 'name'));
    eq(['nut', 'screw'], array_column(QueryBuilder::table('items')->orderBy('id')->limit(2, 1)->get(), 'name'), 'limit 2 offset 1');
    eq([['name' => 'bolt']], QueryBuilder::table('items')->select(['name'])->orderBy('id')->limit(1)->get());
    eq(4, QueryBuilder::table('items')->count());
    eq(2, QueryBuilder::table('items')->where('qty', 10)->count());
    ok(QueryBuilder::table('items')->where('name', 'nut')->exists() && !QueryBuilder::table('items')->where('name', 'nope')->exists());
});

test('QueryBuilder: update and delete return affected rows; empty data is refused', function () {
    items_db();
    eq(2, QueryBuilder::table('items')->where('qty', 10)->update(['qty' => 11, 'note' => 'bulk']));
    eq(2, QueryBuilder::table('items')->where('qty', 11)->count());
    eq(1, QueryBuilder::table('items')->where('name', 'nut')->delete());
    eq(3, QueryBuilder::table('items')->count());
    throws(InvalidArgumentException::class, fn() => QueryBuilder::table('items')->update([]), 'at least one column');
    throws(InvalidArgumentException::class, fn() => QueryBuilder::table('items')->insert([]), 'at least one column');
});

test('QueryBuilder: values are bound, never interpolated', function () {
    items_db();
    $evil = "x'; DROP TABLE items; --";
    eq([], QueryBuilder::table('items')->where('name', $evil)->get());
    QueryBuilder::table('items')->insert(['name' => $evil, 'qty' => 1]);
    eq($evil, QueryBuilder::table('items')->where('name', $evil)->first()['name'], 'stored as plain text');
    eq(5, QueryBuilder::table('items')->count(), 'table intact');
    eq(1, QueryBuilder::table('items')->where('name', $evil)->update(['note' => "o'brien"]));
    eq("o'brien", QueryBuilder::table('items')->where('name', $evil)->first()['note']);
});

test('QueryBuilder: identifiers, operators and directions are validated', function () {
    items_db();
    foreach ([
        fn() => new QueryBuilder('items; DROP TABLE items'),
        fn() => QueryBuilder::table('items')->where('name; --', 1),
        fn() => QueryBuilder::table('items')->where('name', 'LIKE; DROP', 'x'),
        fn() => QueryBuilder::table('items')->where('name', '= 1 OR 1=1 --', 'x'),
        fn() => QueryBuilder::table('items')->orderBy('name', 'ASC; DROP'),
        fn() => QueryBuilder::table('items')->orderBy('na me'),
        fn() => QueryBuilder::table('items')->select(['name, (SELECT 1)']),
        fn() => QueryBuilder::table('items')->whereIn('x)--', [1]),
        fn() => QueryBuilder::table('items')->insert(['name) VALUES (1); --' => 1]),
        fn() => QueryBuilder::table('items')->update(['a=1, b' => 1]),
        fn() => QueryBuilder::table('items')->limit(-1),
    ] as $i => $bad) {
        throws(InvalidArgumentException::class, $bad, '', "case $i");
    }
    ok(QueryBuilder::table('items')->where('items.name', 'bolt')->exists(), 'table.column is allowed');
});

test('QueryBuilder::executeSql(): bound parameters, fetch modes, lastId, array SQL', function () {
    items_db();
    eq([['name' => 'bolt']], QueryBuilder::executeSql('SELECT name FROM items WHERE qty = :q AND active = :a', ['q' => 10, 'a' => 1]));
    eq(['name' => 'nut'], QueryBuilder::executeSql('SELECT name FROM items WHERE id = :id', ['id' => 2], 'fetch'));
    eq([], QueryBuilder::executeSql('SELECT name FROM items WHERE id = 99', 'fetch'), 'params may be omitted');
    eq(2, QueryBuilder::executeSql('UPDATE items SET qty = 1 WHERE qty = :q', ['q' => 10], 'rowCount'));
    eq(5, QueryBuilder::executeSql("INSERT INTO items (name) VALUES ('new')", [], 'lastId'));
    eq(4, count(QueryBuilder::executeSql(['SELECT * FROM items', 'WHERE active = 1'])));
});

test('QueryBuilder: transactions commit and roll back', function () {
    items_db();
    ok(!QueryBuilder::inTransaction());
    QueryBuilder::beginTrans();
    ok(QueryBuilder::inTransaction());
    QueryBuilder::table('items')->insert(['name' => 'tx']);
    QueryBuilder::rollbackTrans();
    eq(4, QueryBuilder::table('items')->count());

    QueryBuilder::beginTrans();
    QueryBuilder::table('items')->insert(['name' => 'tx']);
    QueryBuilder::commitTrans();
    eq(5, QueryBuilder::table('items')->count());
});

test('Model: table name comes from the class name (snake_case) unless overridden', function () {
    eq('journal_entries', JournalEntries::tableName());
    eq('coa', Coa::tableName());
    eq('special_table', Special::tableName());
    ok(new Items() instanceof ModelContract);
});

test('Model: getAll, getOne, exists, updateColumns, setActive, deleteRows', function () {
    items_db();
    eq(4, count(Items::getAll()));
    eq(['bolt', 'nut', 'washer'], array_column(Items::getAll(1), 'name'));
    eq(['screw'], array_column(Items::getAll(0), 'name'));
    eq(['washer', 'screw', 'nut', 'bolt'], array_column(Items::getAll(null, 'active', 'id', 'DESC'), 'name'));
    eq('nut', Items::getOne(2)['name']);
    eq(3, Items::getOne('screw', 'name')['id']);
    eq(false, Items::getOne(99));

    ok(Items::exists('name', 'bolt') && !Items::exists('name', 'ghost'));
    ok(!Items::exists('name', 'bolt', 1), 'the row being edited is ignored');
    ok(Items::exists('name', 'bolt', 2));

    eq(1, Items::updateColumns(['qty' => 99], 2));
    eq(99, Items::getOne(2)['qty']);
    eq(1, Items::setActive(0, 1));
    eq(0, Items::getOne(1)['active']);
    eq(1, Items::deleteRows('washer', 'name'));
    eq(3, count(Items::getAll()));
});

test('Model: paginate() returns data, totals and page counts', function () {
    items_db();
    $p = Items::paginate(1, 3, 'id');
    eq(['bolt', 'nut', 'screw'], array_column($p['data'], 'name'));
    eq(4, $p['total']);
    eq(2, $p['last_page']);
    eq(1, $p['page']);
    $p = Items::paginate(2, 3, 'id');
    eq(['washer'], array_column($p['data'], 'name'));
    eq(0, count(Items::paginate(5, 3)['data']), 'past the end');
    eq(1, Items::paginate(0, 0)['page'], 'page and size are clamped');
    eq(1, Items::paginate(1, 100)['last_page']);
});

test('Model: query(), table() and raw() give builders and bound SQL; transactions are shared', function () {
    items_db();
    eq(2, Items::query()->where('qty', 10)->count());
    eq(4, Items::table('items')->count());
    eq([['n' => 4]], Items::raw('SELECT COUNT(*) AS n FROM items'));
    eq(['name' => 'bolt'], Items::raw('SELECT name FROM items WHERE id = :id', ['id' => 1], 'fetch'));
    Items::beginTrans();
    ok(Items::inTransaction());
    Items::query()->where('id', 1)->delete();
    Items::rollbackTrans();
    eq(4, Items::query()->count());
    Items::beginTrans();
    Items::query()->where('id', 1)->delete();
    Items::commitTrans();
    eq(3, Items::query()->count());
});

test('Database: connection settings, PDO attributes, unsupported drivers, no credentials in errors', function () {
    $pdo = Database::connect(['driver' => 'sqlite', 'name' => ':memory:']);
    eq(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    eq(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));

    ok(Database::connect(['dsn' => 'sqlite::memory:']) instanceof PDO, 'a full dsn overrides the rest');
    throws(InvalidArgumentException::class, fn() => Database::connect(['driver' => 'oracle']), 'Unsupported database driver');

    $e = throws(PDOException::class, fn() => Database::connect(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'name' => 'x', 'user' => 'root', 'pass' => 'sup3rSecret']), 'Could not connect');
    lacks('sup3rSecret', $e->getMessage());

    Database::use($pdo);
    ok(Database::connection() === $pdo);
    Database::reset();
    boot_app(['config/database.php' => "<?php return ['driver' => 'sqlite', 'name' => ':memory:'];"], boot: false);
    ok(Database::connection() instanceof PDO, 'built lazily from config(database)');
});

test('Database: driver strings from the old format ("mysql:") are accepted via config defaults', function () {
    boot_app(['.env' => "DB_CONN=sqlite:\nDB_NAME=:memory:\n"], boot: false);
    eq('sqlite', config('database.driver'));
    ok(Database::connection() instanceof PDO);
});
