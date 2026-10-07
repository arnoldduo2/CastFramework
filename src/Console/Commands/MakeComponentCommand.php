<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;

/**
 * `php cast make:component Btns.Button --props=label:string=Button,variant:'primary'|'ghost'='primary',href:?string,block:bool`
 * creates resources/views/components/btns/button.cast.php with a docblock for every prop and its `??=` default.
 */
final class MakeComponentCommand extends Command
{
    protected string $name = 'make:component';
    protected string $description = 'Create a documented component file with a docblock and defaults';
    protected array $arguments = [
        'Name' => 'The tag, capitalised; dots are folders: Btns.AddNew -> components/btns/add-new.cast.php',
    ];
    protected array $options = [
        '--props=LIST' => 'Comma separated name:type[=default], e.g. label:string=Add,variant:\'primary\'|\'ghost\'=\'primary\',href:?string,block:bool',
        '--force' => 'Overwrite an existing file',
    ];
    protected array $examples = [
        'php cast make:component Card' => 'an empty documented component',
        'php cast make:component Btns.AddNew --props=label:string=Add,href:?string,block:bool' => 'with props',
    ];

    public function handle(Input $input, Output $output): int
    {
        $tag = (string) $input->argument(0, '');
        if (!preg_match('/^[A-Z][A-Za-z0-9_]*(\.[A-Z][A-Za-z0-9_]*)*$/', $tag)) {
            $output->error('Usage: php cast make:component <Name> [--props=label:string,...]   e.g. Btns.Button (capitalised, dots are folders)');
            return 1;
        }

        $props = [];
        $spec = $input->option('props');
        if (is_string($spec) && $spec !== '') {
            foreach ($this->split($spec) as $item) {
                $prop = $this->prop($item);
                if ($prop === null) {
                    $output->error("Cannot read the prop \"$item\". Write name:type or name:type=default, e.g. label:string=Button");
                    return 1;
                }
                $props[] = $prop;
            }
        }

        $ext = (string) Config::get('view.ext', '.cast.php');
        $dir = $this->app->viewsPath() . DIRECTORY_SEPARATOR . trim((string) Config::get('view.components', 'components'), '/\\');
        $segments = array_map(fn($s) => strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $s)), explode('.', $tag));
        $file = $dir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) . $ext;

        if (is_file($file) && !$input->hasOption('force')) {
            $output->error(str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $file) . ' already exists. Use --force to overwrite.');
            return 1;
        }
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
        file_put_contents($file, $this->render($tag, $props));
        $output->info('Created ' . str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $file));
        return 0;
    }

    /** @return list<string> items split at top-level commas (not inside quotes or <>()) */
    private function split(string $spec): array
    {
        $items = [];
        $depth = 0;
        $quote = null;
        $start = 0;
        for ($i = 0, $n = strlen($spec); $i < $n; $i++) {
            $c = $spec[$i];
            if ($quote !== null) {
                if ($c === $quote) $quote = null;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif (str_contains('<(', $c)) {
                $depth++;
            } elseif (str_contains('>)', $c)) {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $items[] = trim(substr($spec, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $items[] = trim(substr($spec, $start));
        return array_values(array_filter($items, fn($i) => $i !== ''));
    }

    /** @return array{name: string, type: string, default: ?string, required: bool}|null */
    private function prop(string $item): ?array
    {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?::([^=]+?))?(?:=(.*))?$/s', $item, $m)) return null;
        $name = $m[1];
        $type = isset($m[2]) && $m[2] !== '' ? trim($m[2]) : 'string';
        $default = $m[3] ?? null;

        $nullable = str_starts_with($type, '?') || (bool) preg_match('/(^|\|)\s*(null|mixed)\s*($|\|)/i', $type);
        $bool = (bool) preg_match('/^\??(bool|boolean)$/i', $type);
        if ($default !== null) {
            $default = $this->literal($type, trim($default));
        } elseif ($bool) {
            $default = 'false';
        } elseif ($nullable) {
            $default = 'null';
        }
        return ['name' => $name, 'type' => $type, 'default' => $default, 'required' => $default === null];
    }

    /** A PHP literal for a default given on the command line: numbers, true/false/null and PHP-looking values stay, text is quoted. */
    private function literal(string $type, string $value): string
    {
        if (preg_match('/^(true|false|null|-?\d+(\.\d+)?|\[.*\]|([\'"]).*\3)$/si', $value)) return $value;
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /** @param list<array{name: string, type: string, default: ?string, required: bool}> $props */
    private function render(string $tag, array $props): string
    {
        $doc = "/**\n * " . str_replace('.', ' ', preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $tag)) . " component. Describe what it is for.\n";
        foreach ($props as $p) {
            $doc .= ' * @var ' . $p['type'] . ' $' . $p['name'] . ' Describe ' . $p['name'] . "\n";
        }
        $doc .= " */\n";

        $code = '';
        foreach ($props as $p) {
            if ($p['default'] !== null) $code .= '$' . $p['name'] . ' ??= ' . $p['default'] . ";\n";
        }
        $children = "<div class=\"" . strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|\./', '-', $tag)) . "\">\n    <?= \$children ?>\n</div>\n";
        return "<?php\n" . $doc . $code . "?>\n" . $children;
    }
}
