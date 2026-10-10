<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\App\Application;
use Cast\Console\{Command, Input, Output};
use Cast\Core\Modules\Modules;

/** `modules:list`, `modules:check`, `modules:enable <name>`, `modules:disable <name>`: module gating from the console (config/modules.php). */
final class ModulesCommand extends Command
{
    public function __construct(Application $app, private string $action = 'list')
    {
        parent::__construct($app);
        $this->name = "modules:$action";
        [$this->description, $this->arguments, $this->options, $this->examples] = match ($action) {
            'list' => ['List the modules, core or optional, and whether each is active, inactive or unbuilt', [], ['--json' => 'Print the modules as JSON'],
                ['php cast modules:list' => 'every module and its state']],
            'check' => ['Fail when a core module is not active (for CI and deploys); optional ones only warn', [], [],
                ['php cast modules:check' => 'exit code 1 when a core module is inactive, unbuilt or missing']],
            'enable' => ['Switch a module on (kept in storage/framework/modules.json)', ['name' => 'The module, as listed in config/modules.php'], [],
                ['php cast modules:enable reports' => 'turn the reports module on']],
            default => ['Switch an optional module off (core modules cannot be switched off)', ['name' => 'The module, as listed in config/modules.php'], [],
                ['php cast modules:disable reports' => 'its routes show the "module inactive" page']],
        };
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return ['list', 'check', 'enable', 'disable'];
    }

    public function handle(Input $input, Output $output): int
    {
        /** @var Modules $modules */
        $modules = $this->app->make('modules');
        return match ($this->action) {
            'list' => $this->list($modules, $input, $output),
            'check' => $this->check($modules, $output),
            default => $this->toggle($modules, (string) $input->argument(0), $this->action === 'enable', $output),
        };
    }

    private function list(Modules $modules, Input $input, Output $output): int
    {
        $all = $modules->all();
        if ($input->hasOption('json')) {
            $output->line((string) json_encode(['enabled' => $modules->enabled(), 'modules' => $all], JSON_PRETTY_PRINT));
            return 0;
        }
        if (!$all) {
            $output->line('No modules are listed. Add them to config/modules.php (php cast make:config modules).');
            return 0;
        }
        if (!$modules->enabled()) $output->warn('Module gating is off (modules.enabled / CAST_MODULES=true turns it on): every module counts as active.');
        $rows = array_map(fn($m) => [$m['name'], $m['core'] ? 'core' : 'optional', $m['state'], $m['reason']], $all);
        $output->table(['Module', 'Type', 'State', 'Why'], $rows);
        return 0;
    }

    private function check(Modules $modules, Output $output): int
    {
        if (!$modules->enabled()) {
            $output->line('Module gating is off; nothing to check.');
            return 0;
        }
        $bad = 0;
        foreach ($modules->all() as $m) {
            if ($m['state'] === 'active') {
                $output->info("  ok    {$m['name']} (" . ($m['core'] ? 'core' : 'optional') . ')');
                continue;
            }
            $label = ($m['core'] ? 'core' : 'optional') . " module {$m['name']} is {$m['state']}" . ($m['reason'] !== '' ? " ({$m['reason']})" : '');
            if ($m['core']) {
                $bad++;
                $output->error("  FAIL  $label");
            } else {
                $output->warn("  note  $label");
            }
        }
        $output->line();
        $bad === 0 ? $output->info('Every core module is active.') : $output->error("$bad core module(s) not active: their routes show the fallback page.");
        return $bad === 0 ? 0 : 1;
    }

    private function toggle(Modules $modules, string $name, bool $on, Output $output): int
    {
        if ($name === '' || !$modules->has($name)) {
            $output->error("There is no module \"$name\" in config/modules.php. See  php cast modules:list");
            return 1;
        }
        if (!$on && $modules->isCore($name)) {
            $output->error("$name is a core module: the app cannot work without it, so it cannot be switched off. Move it to 'optional' if it can be.");
            return 1;
        }
        $modules->store()?->set($name, $on);
        $output->info("$name is now " . ($on ? 'on' : 'off') . '.');
        return 0;
    }
}
