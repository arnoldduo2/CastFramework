<?php

declare(strict_types=1);

namespace Cast\Core\Modules;

use Cast\App\Application;
use Cast\Contracts\ModuleStore;
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
 * With gating off (the default) every gate lets everything through and every state is 'active'.
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
        ];
    }

    /** Is gating switched on at all? */
    public function enabled(): bool
    {
        return (bool) Config::get('modules.enabled', false);
    }

    /** @return array{name: string, title: string, core: bool, state: string, reason: string} */
    public function status(string $name): array
    {
        $m = $this->modules[$name] ?? null;
        if ($m === null) {
            return ['name' => $name, 'title' => ucwords(str_replace(['-', '_'], ' ', $name)), 'core' => false, 'state' => $this->enabled() ? 'unregistered' : 'active',
                'reason' => $this->enabled() ? "The module \"$name\" is not listed in config/modules.php." : ''];
        }
        $result = ['name' => $name, 'title' => $m['title'], 'core' => $m['core'], 'state' => 'active', 'reason' => ''];
        if (!$this->enabled()) return $result;

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

    /** @return list<array{name: string, title: string, core: bool, state: string, reason: string}> core modules first */
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
