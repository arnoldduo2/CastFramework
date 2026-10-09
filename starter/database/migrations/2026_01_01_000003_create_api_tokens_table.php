<?php

declare(strict_types=1);

use Cast\Database\{Blueprint, Migration, Schema};

// The table the API tokens are kept in (`php cast token:schema --migration` writes this file).
/*
 * A migration changes the database: up() makes the change, down() undoes it. Run them with  php cast migrate  (php cast migrate:rollback undoes
 * the last batch, php cast migrate:status lists them). Create one with  php cast make:migration create_orders_table.
 * The file name starts with a date so they always run in order; never edit a migration that has run on a shared database, add a new one.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('api_tokens', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('user_id', 64);
            $table->string('name', 100);
            $table->char('token_hash', 64);
            $table->text('abilities');
            $table->bigInteger('created_at');
            $table->bigInteger('last_used_at')->nullable();
            $table->bigInteger('expires_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('api_tokens');
    }
};
