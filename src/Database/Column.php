<?php

declare(strict_types=1);

namespace Cast\Database;

/** One column of a table, built fluently by {@see Blueprint}: `$table->string('email', 120)->unique()->nullable()`. */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unsigned = false;
    public bool $useCurrent = false;
    public bool $autoIncrement = false;
    public bool $primary = false;
    public ?string $after = null;
    public ?string $comment = null;

    /** @param array<string, mixed> $attributes length, precision, scale, values */
    public function __construct(
        public readonly Blueprint $table,
        public readonly string $type,
        public readonly string $name,
        public readonly array $attributes = [],
    ) {}

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    /** A literal (string, number, bool, null) or `new Raw('CURRENT_TIMESTAMP')`. */
    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    public function unique(?string $name = null): self
    {
        $this->table->unique($this->name, $name);
        return $this;
    }

    public function index(?string $name = null): self
    {
        $this->table->index($this->name, $name);
        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;
        return $this;
    }

    /** MySQL only: place the column after another one (ignored elsewhere). */
    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    public function comment(string $text): self
    {
        $this->comment = $text;
        return $this;
    }

    /** For `foreignId()`: add the foreign key to `$table` (default: the plural of the column name without `_id`) and `id`. */
    public function constrained(?string $table = null, string $column = 'id'): ForeignKey
    {
        $table ??= self::pluralTable($this->name);
        return $this->table->foreign($this->name)->references($column)->on($table);
    }

    private static function pluralTable(string $column): string
    {
        $base = preg_replace('/_id$/', '', $column);
        if (str_ends_with($base, 'y') && !preg_match('/[aeiou]y$/', $base)) return substr($base, 0, -1) . 'ies';
        return preg_match('/(s|x|z|ch|sh)$/', $base) ? $base . 'es' : $base . 's';
    }
}
