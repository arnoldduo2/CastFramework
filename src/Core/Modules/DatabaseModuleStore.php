<?php

declare(strict_types=1);

namespace Cast\Core\Modules;

use Cast\Contracts\{ModuleStore, TierStore};
use Cast\Core\QueryBuilder;

/**
 * Module switches and the plan kept in a database table, so an admin screen (or your own SQL) controls them. Turn it on with
 * `'store' => 'database'` in config/modules.php and create the table with  php cast modules:table --migration  (or --run).
 *
 * One row per module: name, enabled (true/false, null = follow the config). The plan is the row named "@tier", its value column holding
 * e.g. "professional". Rows are read once per request. A missing table is treated as "no opinion", so the app still runs while you migrate.
 */
final class DatabaseModuleStore implements ModuleStore, TierStore
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private string $table = 'modules') {}

    public function isEnabled(string $module): ?bool
    {
        $row = $this->rows()[$module] ?? null;
        return $row && $row['enabled'] !== null ? (bool) $row['enabled'] : null;
    }

    public function set(string $module, bool $enabled): void
    {
        $this->save($module, ['enabled' => $enabled ? 1 : 0]);
    }

    public function tier(): ?string
    {
        $value = $this->rows()['@tier']['value'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setTier(string $tier): void
    {
        $this->save('@tier', ['value' => $tier]);
    }

    /** @param array<string, mixed> $data */
    private function save(string $name, array $data): void
    {
        $existing = QueryBuilder::table($this->table)->where('name', $name)->first();
        if ($existing) {
            QueryBuilder::table($this->table)->where('name', $name)->update($data);
        } else {
            QueryBuilder::table($this->table)->insert($data + ['name' => $name, 'enabled' => null, 'value' => null]);
        }
        $this->rows = null;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->rows !== null) return $this->rows;
        $this->rows = [];
        try {
            foreach (QueryBuilder::table($this->table)->get() as $row) $this->rows[(string) $row['name']] = $row;
        } catch (\Throwable) {
            // no table yet, or no database: no opinion
        }
        return $this->rows;
    }

    /** The migration that creates the table (`php cast modules:table --migration`). */
    public static function migration(string $table): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Cast\\Database\\{Blueprint, Migration, Schema};

/*
 * The modules table: one row per module (name, enabled: 1 on, 0 off, NULL follows config/modules.php) and one row named "@tier" whose
 * value is the plan this install is on (essentials, professional, enterprise ...). Used when config/modules.php has 'store' => 'database'.
 */
return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        \$schema->create('$table', function (Blueprint \$table) {
            \$table->id();
            \$table->string('name', 120)->unique();
            \$table->boolean('enabled')->nullable();
            \$table->string('value', 120)->nullable();
            \$table->timestamps();
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
