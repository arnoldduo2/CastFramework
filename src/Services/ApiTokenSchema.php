<?php

declare(strict_types=1);

namespace Cast\Services;

/** The `api_tokens` table: as a migration file (`php cast token:schema --migration`), as SQL for any driver, or created directly (`--run`). */
final class ApiTokenSchema
{
    public static function sql(string $driver = 'mysql', string $table = 'api_tokens'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name \"$table\".");
        }
        $big = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        return "CREATE TABLE IF NOT EXISTS $table (\n"
            . "    id VARCHAR(32) NOT NULL PRIMARY KEY,\n"
            . "    user_id VARCHAR(64) NOT NULL,\n"
            . "    name VARCHAR(100) NOT NULL,\n"
            . "    token_hash CHAR(64) NOT NULL,\n"
            . "    abilities TEXT NOT NULL,\n"
            . "    created_at $big NOT NULL,\n"
            . "    last_used_at $big NULL,\n"
            . "    expires_at $big NULL,\n"
            . "    revoked_at $big NULL\n"
            . ")$engine";
    }

    /** The source of a migration file that creates the table. */
    public static function migration(string $table = 'api_tokens'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name \"$table\".");
        }
        return <<<PHP
<?php

declare(strict_types=1);

use Cast\\Database\\{Blueprint, Migration, Schema};

// The table the API tokens are kept in (created by `php cast token:schema --migration`).
return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        \$schema->create('$table', function (Blueprint \$table) {
            \$table->string('id', 32)->primary();
            \$table->string('user_id', 64);
            \$table->string('name', 100);
            \$table->char('token_hash', 64);
            \$table->text('abilities');
            \$table->bigInteger('created_at');
            \$table->bigInteger('last_used_at')->nullable();
            \$table->bigInteger('expires_at')->nullable();
            \$table->bigInteger('revoked_at')->nullable();
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->dropIfExists('$table');
    }
};

PHP;
    }
}
