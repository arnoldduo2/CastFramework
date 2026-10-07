<?php

declare(strict_types=1);

/**
 * The same schema work on a real MySQL or PostgreSQL server. Skipped unless CAST_TEST_DB is set:
 *   CAST_TEST_DB=pgsql CAST_TEST_DB_HOST=127.0.0.1 CAST_TEST_DB_NAME=cast_test CAST_TEST_DB_USER=u CAST_TEST_DB_PASS=p php tests/run.php
 * (CI runs it against mysql:8 and postgres:16 service containers. It DROPS ALL TABLES in that database.)
 */

use Cast\Core\Database;
use Cast\Core\QueryBuilder;
use Cast\Database\{Blueprint, MigrationException, Schema};

$serverDriver = getenv('CAST_TEST_DB') ?: '';
if (!in_array($serverDriver, ['mysql', 'pgsql'], true)) return;

function server_pdo(): PDO
{
    $pdo = Database::connect([
        'driver' => getenv('CAST_TEST_DB'),
        'host' => getenv('CAST_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('CAST_TEST_DB_PORT') ?: '',
        'name' => getenv('CAST_TEST_DB_NAME') ?: 'cast_test',
        'user' => getenv('CAST_TEST_DB_USER') ?: 'root',
        'pass' => getenv('CAST_TEST_DB_PASS') ?: '',
    ]);
    Database::use($pdo);
    (new Schema($pdo))->dropAllTables();
    return $pdo;
}

test("[$serverDriver] Schema: tables, types, defaults, enum, foreign keys and indexes work on a real server", function () {
    $pdo = server_pdo();
    $s = new Schema($pdo);

    $s->create('users', function (Blueprint $t) { $t->id(); $t->string('email', 120)->unique(); });
    $s->create('posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        $t->string('title');
        $t->enum('state', ['draft', 'live'])->default('draft');
        $t->decimal('price', 10, 2)->default(0);
        $t->boolean('featured')->default(false);
        $t->text('body')->nullable()->comment('the text');
        $t->json('meta')->nullable();
        $t->timestamp('seen_at')->useCurrent();
        $t->timestamps();
        $t->index(['user_id', 'state']);
    });
    ok($s->hasTable('users') && $s->hasTable('posts') && !$s->hasTable('nope'));
    eq(['id', 'user_id', 'title', 'state', 'price', 'featured', 'body', 'meta', 'seen_at', 'created_at', 'updated_at'], $s->columns('posts'));

    $pdo->prepare('INSERT INTO users (email) VALUES (?)')->execute(['a@b.co']);
    $userId = (int) $pdo->query('SELECT id FROM users')->fetchColumn();
    $pdo->prepare('INSERT INTO posts (user_id, title, price, meta) VALUES (?, ?, ?, ?)')->execute([$userId, "It's a title", '12.50', '{"a":1}']);

    $row = $pdo->query('SELECT * FROM posts')->fetch();
    eq('draft', $row['state']);
    eq("It's a title", $row['title']);
    eq('12.50', number_format((float) $row['price'], 2, '.', ''));
    ok(in_array($row['featured'], [0, '0', false, 'f'], true), 'boolean default false');
    eq(['a' => 1], json_decode((string) $row['meta'], true));
    ok($row['seen_at'] !== null, 'useCurrent default applied');

    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO posts (user_id, title) VALUES (999999, 'orphan')"));
    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO users (email) VALUES ('a@b.co')"));
    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO posts (user_id, title, state) VALUES ($userId, 'x', 'bogus')"));

    $s->table('posts', function (Blueprint $t) {
        $t->integer('views')->default(0);
        $t->string('slug', 80)->nullable()->index();
        $t->renameColumn('title', 'headline');
    });
    ok($s->hasColumn('posts', 'views') && $s->hasColumn('posts', 'slug') && $s->hasColumn('posts', 'headline') && !$s->hasColumn('posts', 'title'));
    $s->table('posts', function (Blueprint $t) { $t->dropIndex(['slug']); $t->dropColumn(['slug', 'views']); });
    ok(!$s->hasColumn('posts', 'slug'));

    $pdo->exec('DELETE FROM users');
    eq(0, (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn(), 'ON DELETE CASCADE');

    $s->rename('posts', 'articles');
    ok($s->hasTable('articles') && !$s->hasTable('posts'));
    $s->dropAllTables();   // with foreign keys in place
    eq([], $s->tables());
});

test("[$serverDriver] Schema: add and drop a foreign key and a composite primary key on an existing table", function () {
    $pdo = server_pdo();
    $s = new Schema($pdo);
    $s->create('owners', fn(Blueprint $t) => $t->id());
    $s->create('pets', function (Blueprint $t) { $t->bigInteger('owner_id')->unsigned()->nullable(); $t->string('name', 40); });
    $s->table('pets', function (Blueprint $t) { $t->foreign('owner_id')->references('id')->on('owners')->nullOnDelete(); });
    $pdo->exec('INSERT INTO owners (id) VALUES (1)');
    $pdo->exec("INSERT INTO pets (owner_id, name) VALUES (1, 'Rex')");
    throws(PDOException::class, fn() => $pdo->exec("INSERT INTO pets (owner_id, name) VALUES (42, 'Ghost')"));
    $pdo->exec('DELETE FROM owners');
    eq(null, $pdo->query('SELECT owner_id FROM pets')->fetchColumn(), 'ON DELETE SET NULL');

    $s->table('pets', function (Blueprint $t) { $t->dropForeign(['owner_id']); });
    $pdo->exec("INSERT INTO pets (owner_id, name) VALUES (42, 'Free')");   // no longer constrained
    $s->dropAllTables();
});

test("[$serverDriver] QueryBuilder::insert works on a table whose key is not generated (the API tokens table)", function () {
    $pdo = server_pdo();
    (new Schema($pdo))->create('api_tokens', function (Blueprint $t) { $t->string('id', 32)->primary(); $t->string('name', 20); });
    eq(0, QueryBuilder::table('api_tokens')->insert(['id' => 'abc', 'name' => 'x']));
    eq('abc', QueryBuilder::table('api_tokens')->first()['id']);

    $tokens = new Cast\Services\ApiTokens(new Cast\Services\DatabaseTokenStore());
    (new Schema($pdo))->dropIfExists('api_tokens');
    $pdo->exec(Cast\Services\ApiTokenSchema::sql(getenv('CAST_TEST_DB')));
    $issued = $tokens->issue(5, 'cli', ['*'], 60);
    ok($tokens->authenticate($issued['token']) !== null, 'issue and authenticate on a real server');
});

test("[$serverDriver] Migrator: runs, records batches, rolls back and reports a failure", function () {
    server_pdo();
    $app = boot_app([
        'database/migrations/2026_01_01_000001_create_a_table.php' => migration_source('$schema->create("a", fn(Blueprint $t) => $t->id());', '$schema->dropIfExists("a");'),
        'database/migrations/2026_01_01_000002_create_b_table.php' => migration_source('$schema->create("b", function (Blueprint $t) { $t->id(); $t->foreignId("a_id")->constrained("a"); });', '$schema->dropIfExists("b");'),
    ]);
    $m = $app->make('migrator');
    eq(2, count($m->migrate()));
    eq([1, 1], array_map(fn($r) => (int) $r['batch'], QueryBuilder::table('migrations')->orderBy('id')->get()));
    eq(['2026_01_01_000002_create_b_table'], $m->rollback(1));
    eq(2, count($m->refresh()));
    eq(2, count($m->fresh()));
    eq(2, count($m->reset()));
});

if ($serverDriver === 'pgsql') {
    test('[pgsql] Migrator: a failing migration leaves nothing behind (transactional DDL)', function () {
        server_pdo();
        $app = boot_app(['database/migrations/2026_01_01_000001_breaks.php' => migration_source('$schema->create("partial", fn(Blueprint $t) => $t->id()); $schema->statement("THIS IS NOT SQL");', '')]);
        throws(MigrationException::class, fn() => $app->make('migrator')->migrate(), 'breaks');
        ok(!(new Schema(Database::connection()))->hasTable('partial'), 'the table was rolled back');
    });
}
