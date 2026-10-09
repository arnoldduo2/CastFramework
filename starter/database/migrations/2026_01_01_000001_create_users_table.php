<?php

declare(strict_types=1);

use Cast\Database\{Blueprint, Migration, Schema};

/*
 * A migration changes the database: up() makes the change, down() undoes it. Run them with  php cast migrate  (php cast migrate:rollback undoes
 * the last batch, php cast migrate:status lists them). Create one with  php cast make:migration create_orders_table.
 * The file name starts with a date so they always run in order; never edit a migration that has run on a shared database, add a new one.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->text('permissions')->nullable();   // JSON list of permission slugs
            $table->string('last_login', 30)->nullable();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
