<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;

/**
 * Views and components are templates, not classes: the engine puts a line of its own before the file, so a `declare(strict_types=1);`
 * at the top is no longer the first statement and PHP stops with a fatal error. This finds those files (and removes the line with --fix).
 */
final class ViewsCheckCommand extends Command
{
    protected string $name = 'views:check';
    protected string $description = 'Find views that start with declare(strict_types=1), which PHP refuses inside a template';
    protected array $options = [
        '--fix' => 'Remove the declare line from the files found',
    ];
    protected array $examples = [
        'php cast views:check' => 'list the files',
        'php cast views:check --fix' => 'remove the line, then  php cast views:clear',
    ];

    public function handle(Input $input, Output $output): int
    {
        $ext = (string) Config::get('view.ext', '.cast.php');
        $root = $this->app->viewsPath();
        $found = [];
        if (is_dir($root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, $ext) && !str_ends_with($path, '.php')) continue;
                $source = (string) file_get_contents($path);
                if (preg_match(self::PATTERN, $source)) $found[$path] = $source;
            }
        }

        if (!$found) {
            $output->info('No view starts with declare(strict_types=1).');
            return 0;
        }
        $fix = $input->hasOption('fix');
        foreach ($found as $path => $source) {
            $short = str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $path);
            if ($fix) {
                file_put_contents($path, (string) preg_replace(self::PATTERN, '$1', $source, 1));
                $output->info("fixed  $short");
            } else {
                $output->warn("found  $short");
            }
        }
        if ($fix) {
            $output->line('Now clear the compiled views:  php cast views:clear');
            return 0;
        }
        $output->error(count($found) . ' view' . (count($found) === 1 ? '' : 's') . ' would stop PHP with "strict_types declaration must be the very first statement". Remove that line, or run  php cast views:check --fix');
        return 1;
    }

    /** `<?php`, optional comments, then declare(...);  (group 1 keeps what came before it) */
    private const PATTERN = '/\A(\s*<\?php\s+(?:(?:\/\*.*?\*\/|\/\/[^\n]*\n|#[^\n]*\n)\s*)*)declare\s*\(\s*strict_types\s*=\s*[01]\s*\)\s*;[ \t]*\R?/s';
}
