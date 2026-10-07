<?php

declare(strict_types=1);

use Cast\Database\{Blueprint, Migration, Schema};

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->integer('qty')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('items');
    }
};
