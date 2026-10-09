<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\App\Application;
use Cast\Console\{Command, Input, Output};
use Cast\Support\DocsBuilder;

/**
 * `php cast docs:build` turns markdown files into a browsable documentation site: `data.js` (the pages) next to the viewer `index.html`.
 * The framework's own documentation is built this way (`composer docs`) and served at /docs; use it for your project's docs too.
 */
final class DocsBuildCommand extends Command
{
    protected string $name = 'docs:build';
    protected string $description = 'Build the documentation site (data.js + viewer) from markdown files';
    protected array $options = [
        '--source=DIR' => 'Folder with the .md pages (default docs). Each page may start with front matter: title, section, order, description',
        '--readme=FILE' => 'A README to split into one page per "## heading" (placed by <source>/_meta.json)',
        '--out=DIR' => 'Where to write data.js and index.html (default public/docs)',
        '--check' => 'Write nothing: exit 1 when data.js is out of date with the markdown (for CI)',
    ];
    protected array $examples = [
        'php cast docs:build' => 'docs/*.md -> public/docs/ (then open /docs after pointing config static.docs at it)',
        'php cast docs:build --source=docs --readme=README.md --out=public/docs' => 'with a README split into pages',
        'php cast docs:build --check' => 'is data.js up to date?',
    ];

    public function handle(Input $input, Output $output): int
    {
        $path = fn(string $p) => preg_match('#^([a-z]:)?[\\/]#i', $p) ? $p : $this->app->basePath($p);
        $source = $path((string) ($input->option('source') ?: 'docs'));
        $readme = is_string($input->option('readme')) ? $path((string) $input->option('readme')) : null;
        $out = $path((string) ($input->option('out') ?: 'public/docs'));

        if (!is_dir($source)) {
            $output->error("There is no folder $source. Put your .md pages there (or use --source=DIR).");
            return 1;
        }

        $builder = new DocsBuilder();
        $data = $builder->build($source, $readme, null, Application::VERSION);
        $js = $builder->render($data);
        $count = count($data['pages']);

        if ($input->hasOption('check')) {
            $current = is_file("$out/data.js") ? (string) file_get_contents("$out/data.js") : '';
            if ($current === $js) {
                $output->info("data.js is up to date ($count pages).");
                return 0;
            }
            $output->error('data.js is out of date with the markdown. Run  php cast docs:build' . ($input->option('source') ? ' with the same options' : ''));
            return 1;
        }

        if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
            $output->error("Cannot create $out.");
            return 1;
        }
        file_put_contents("$out/data.js", $js);
        $viewer = dirname(__DIR__, 2) . '/Resources/docs/index.html';
        if (!is_file("$out/index.html") && is_file($viewer)) copy($viewer, "$out/index.html");
        $output->info("Built $count pages in " . str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $out) . '/ (data.js' . (is_file("$out/index.html") ? ', index.html' : '') . ')');
        foreach ($data['sections'] as $section) {
            $n = count(array_filter($data['pages'], fn($p) => $p['section'] === $section));
            if ($n) $output->line('  ' . $output->color(str_pad($section, 24), 'blue') . $n . ' page' . ($n === 1 ? '' : 's'));
        }
        return 0;
    }
}
