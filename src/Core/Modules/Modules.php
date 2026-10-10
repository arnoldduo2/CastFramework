<?php

declare(strict_types=1);

namespace Cast\Core\Modules;

use Cast\App\Application;
use Cast\Contracts\{ModuleStore, TierStore};
use Cast\Core\Config;

/**
 * Module gating (opt in: `modules.enabled` in config/modules.php, or CAST_MODULES=true). A module is a part of your app with its own routes
 * (invoices, reports, printing ...). You list the modules once, as core (the app cannot work without them) or optional, and put each group of
 * routes behind its gate:  Router::module('reports', function () { ... });  When a module is switched off, or not built yet, its routes answer with a
 * "module inactive or unavailable" page (JSON for APIs) instead of breaking the app.
 *
 * A module's state:
 *   active        its routes run
 *   inactive      switched off: 'active' => false in the config, or `php cast modules:disable reports`
 *   unbuilt       a class or file it 'requires' is not there yet
 *   unregistered  Router::module('x') names a module that is not listed
 *   locked        the module belongs to a higher plan than this install is on (tiers, below)
 * With gating off (the default) every gate lets everything through and every state is 'active'.
 *
 * Tiers (plans): `modules.tiers` lists them lowest first, e.g. ['essentials', 'professional', 'enterprise']. A module has a 'tier' (default: the
 * lowest); core modules are always in the lowest tier, so the base plan always works. The install's plan is `modules.tier` (a name, or a
 * function returning one, e.g. read from a licence or tenant row), else the one saved in the store (`php cast modules:tier professional`,
 * or the database), else the lowest. A plan includes every tier below it. With no tiers listed there are no plans.
 */
final class Modules
{
    /** @var array<string, array{name: string, title: string, core: bool, active: mixed, requires: list<string>, description: string}> */
    private array $modules = [];

    public function __construct(private ?ModuleStore $store = null)
    {
        foreach (['core' => true, 'optional' => false] as $group => $core) {
            foreach ((array) Config::get("modules.$group", []) as $key => $options) $this->define(is_int($key) ? (string) $options : (string) $key, is_array($options) || !is_int($key) ? (is_array($options) ? $options : ['active' => $options]) : [], $core);
        }
    }

    /** Add a module while the app runs (a package registering its own). */
    public function define(string $name, array $options = [], bool $core = false): void
    {
        $this->modules[$name] = [
            'name' => $name,
            'title' => (string) ($options['title'] ?? ucwords(str_replace(['-', '_'], ' ', $name))),
            'core' => $core,
            'active' => $options['active'] ?? true,
            'requires' => array_values((array) ($options['requires'] ?? [])),
            'description' => (string) ($options['description'] ?? ''),
            'tier' => $core ? '' : strtolower((string) ($options['tier'] ?? '')),     // '' = the lowest tier
        ];
    }

    /** Is gating switched on at all? */
    public function enabled(): bool
    {
        return (bool) Config::get('modules.enabled', false);
    }

    /** @return list<string> the plans, lowest first (empty: no plans) */
    public function tiers(): array
    {
        return array_values(array_filter(array_map(fn($t) => strtolower(trim((string) $t)), (array) Config::get('modules.tiers', []))));
    }

    /** The plan this install is on (null when no tiers are listed). */
    public function tier(): ?string
    {
        $tiers = $this->tiers();
        if (!$tiers) return null;
        $setting = Config::get('modules.tier');
        $tier = is_callable($setting) ? $setting() : null;
        if (!is_string($tier) || $tier === '') $tier = $this->store instanceof TierStore ? $this->store->tier() : null;
        if (!is_string($tier) || $tier === '') $tier = is_string($setting) && !is_callable($setting) ? $setting : null;
        $tier = strtolower(trim((string) $tier));
        return in_array($tier, $tiers, true) ? $tier : $tiers[0];       // unknown or missing: the base plan
    }

    /** Does the plan include this tier (the plan's own and every one below it)? Always true without tiers. */
    public function allows(string $tier): bool
    {
        $tiers = $this->tiers();
        if (!$tiers || $tier === '') return true;
        $need = array_search(strtolower($tier), $tiers, true);
        return $need !== false && $need <= (int) array_search($this->tier(), $tiers, true);
    }

    public function setTier(string $tier): bool
    {
        if (!in_array(strtolower($tier), $this->tiers(), true) || !$this->store instanceof TierStore) return false;
        $this->store->setTier(strtolower($tier));
        return true;
    }

    /** @return array{name: string, title: string, core: bool, tier: string, state: string, reason: string} */
    public function status(string $name): array
    {
        $m = $this->modules[$name] ?? null;
        $base = $this->tiers()[0] ?? '';
        if ($m === null) {
            return ['name' => $name, 'title' => ucwords(str_replace(['-', '_'], ' ', $name)), 'core' => false, 'tier' => $base, 'state' => $this->enabled() ? 'unregistered' : 'active',
                'reason' => $this->enabled() ? "The module \"$name\" is not listed in config/modules.php." : ''];
        }
        $tier = $m['tier'] !== '' ? $m['tier'] : $base;
        $result = ['name' => $name, 'title' => $m['title'], 'core' => $m['core'], 'tier' => $tier, 'state' => 'active', 'reason' => ''];
        if (!$this->enabled()) return $result;

        if (!$this->allows($tier)) {
            $plan = $this->tier();
            return [...$result, 'state' => 'locked', 'reason' => 'It is part of the ' . ucfirst($tier) . ' plan; this install is on ' . ucfirst((string) $plan) . '.'];
        }
        foreach ($m['requires'] as $need) {
            if (!$this->exists($need)) return [...$result, 'state' => 'unbuilt', 'reason' => "$need is not there yet."];
        }
        $switch = $this->store?->isEnabled($name);
        $on = $switch ?? (is_callable($m['active']) ? (bool) ($m['active'])() : (bool) $m['active']);
        return $on ? $result : [...$result, 'state' => 'inactive', 'reason' => 'It is switched off.'];
    }

    public function isActive(string $name): bool
    {
        return $this->status($name)['state'] === 'active';
    }

    /** @return list<array{name: string, title: string, core: bool, tier: string, state: string, reason: string}> core modules first */
    public function all(): array
    {
        $list = array_map(fn($n) => $this->status($n), array_keys($this->modules));
        usort($list, fn($a, $b) => [!$a['core'], $a['name']] <=> [!$b['core'], $b['name']]);
        return $list;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function isCore(string $name): bool
    {
        return $this->modules[$name]['core'] ?? false;
    }

    public function store(): ?ModuleStore
    {
        return $this->store;
    }

    /** A class name, or a file (relative to the app folder). */
    private function exists(string $need): bool
    {
        if (preg_match('#[/\\\\]|\.\w{2,4}$#', $need)) {
            $app = Application::instance();
            $path = str_starts_with($need, '/') || preg_match('#^[a-z]:[\\\\/]#i', $need) ? $need : ($app ? $app->basePath($need) : $need);
            return file_exists($path);
        }
        return class_exists($need) || interface_exists($need) || trait_exists($need);
    }
}
