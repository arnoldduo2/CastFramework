<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

/**
 * Editors (Intelephense, PHP IntelliSense) only know a function when they have read the file that defines it. When `vendor/` is
 * excluded from indexing, or the helpers are loaded in a way the editor cannot follow, every helper is "Undefined function".
 * This writes `_ide_helpers.php`: the signatures of the framework's global helpers (and your `helpers.custom` ones). The app never loads it.
 */
final class IdeHelpersCommand extends Command
{
    protected string $name = 'ide:helpers';
    protected string $description = 'Write _ide_helpers.php so the editor knows the global helper functions (no more "Undefined function")';
    protected array $options = [
        '--path=FILE' => 'Where to write it (default _ide_helpers.php in the app folder)',
    ];
    protected array $examples = [
        'php cast ide:helpers' => 'then reload the editor window',
    ];

    public function handle(Input $input, Output $output): int
    {
        $helperDirs = [realpath(dirname(__DIR__, 2) . '/Helpers')];
        $custom = \Cast\Core\Config::get('helpers.custom');
        if (is_string($custom) && is_dir($custom)) $helperDirs[] = realpath($custom);

        $functions = [];
        foreach (get_defined_functions()['user'] as $name) {
            $ref = new \ReflectionFunction($name);
            $file = (string) $ref->getFileName();
            foreach ($helperDirs as $dir) {
                if ($dir !== false && str_starts_with(realpath($file) ?: $file, $dir)) $functions[$name] = $ref;
            }
        }
        ksort($functions);

        $code = "<?php\n\n/**\n * Written by `php cast ide:helpers`. For editors only: the application never loads this file.\n * Regenerate it after updating the framework or adding helpers.\n *\n * @noinspection ALL\n */\n\n";
        foreach ($functions as $name => $ref) {
            $code .= $this->declaration($ref);
        }
        $path = $input->option('path');
        $file = is_string($path) && $path !== '' ? (preg_match('#^([a-z]:)?[\\/]#i', $path) ? $path : $this->app->basePath($path)) : $this->app->basePath('_ide_helpers.php');
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);
        file_put_contents($file, $code);
        $output->info('Wrote ' . basename($file) . ' (' . count($functions) . ' helper functions). Reload the editor window if it still shows "Undefined function".');
        return 0;
    }

    private function declaration(\ReflectionFunction $ref): string
    {
        $params = [];
        foreach ($ref->getParameters() as $p) {
            $text = ($p->hasType() ? $p->getType() . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
            if ($p->isDefaultValueAvailable()) {
                $text .= ' = ' . ($p->isDefaultValueConstant() ? '\\' . ltrim((string) $p->getDefaultValueConstantName(), '\\') : $this->literal($p->getDefaultValue()));
            }
            $params[] = $text;
        }
        $doc = $ref->getDocComment();
        $return = $ref->hasReturnType() ? ': ' . $ref->getReturnType() : '';
        return ($doc ? $doc . "\n" : '') . 'function ' . $ref->getName() . '(' . implode(', ', $params) . ')' . $return . " {}\n\n";
    }

    private function literal(mixed $value): string
    {
        return str_replace(["\n", '  '], ['', ' '], var_export($value, true));
    }
}
