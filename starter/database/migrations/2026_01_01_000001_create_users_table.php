<?php

declare(strict_types=1);

use Cast\Database\{Blueprint, Migration, Schema};

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
