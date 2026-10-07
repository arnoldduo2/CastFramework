<?php

declare(strict_types=1);

use Cast\Database\{Blueprint, Migration, Schema};

// The table the API tokens are kept in (`php cast token:schema --migration` writes this file).
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
