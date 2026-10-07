<?php

declare(strict_types=1);

namespace Cast\Database;

/**
 * A migration file returns an object of an anonymous subclass:
 *
 *   return new class extends Migration {
 *       public function up(Schema $schema): void {
 *           $schema->create('orders', function (Blueprint $table) {
 *               $table->id();
 *               $table->string('number', 30)->unique();
 *               $table->decimal('total', 12, 2)->default(0);
 *               $table->timestamps();
 *           });
 *       }
 *       public function down(Schema $schema): void { $schema->dropIfExists('orders'); }
 *   };
 */
abstract class Migration
{
    /** Run inside a transaction when the database supports transactional DDL (PostgreSQL, SQLite). MySQL commits DDL itself. */
    public bool $transactional = true;

    abstract public function up(Schema $schema): void;

    abstract public function down(Schema $schema): void;
}
