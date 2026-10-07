<?php

declare(strict_types=1);

namespace Cast\Database;

/** `$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()` */
final class ForeignKey
{
    public string $referencedTable = '';
    /** @var list<string> */
    public array $referencedColumns = ['id'];
    public ?string $onDelete = null;
    public ?string $onUpdate = null;

    private const ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];

    /** @param list<string> $columns */
    public function __construct(public readonly array $columns, public ?string $name = null) {}

    public function references(string|array $columns): self
    {
        $this->referencedColumns = array_values((array) $columns);
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $this->action($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $this->action($action);
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    private function action(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid foreign key action \"$action\".");
        }
        return $action;
    }
}
