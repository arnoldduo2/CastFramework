<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

/**
 * `php cast editor:install`: alias for `php cast engine:editor install`. The `.cast.php` editor extension
 * (highlighting, Ctrl+click on components, snippets) is owned and shipped by CastTemplateEngine, the package
 * that owns the syntax it teaches editors to understand — this command only forwards to it, so the extension
 * never has to be duplicated and kept in sync by hand inside CastFramework.
 */
final class EditorInstallCommand extends Command
{
    protected string $name = 'editor:install';
    protected string $description = 'Alias for `engine:editor install` — installs the editor extension for .cast.php views (LSP, Tree-sitter, syntax highlighting, Ctrl+click, props), maintained by CastTemplateEngine';
    protected array $options = [
        '--editor=NAME' => 'Only this editor: code, insiders, vscodium, cursor, antigravity or windsurf (default: every editor found)',
        '--uninstall' => 'Remove the extension',
    ];
    protected array $examples = [
        'php cast editor:install' => 'same as `php cast engine:editor install`',
        'php cast editor:install --editor=code' => 'VS Code only',
        'php cast editor:install --uninstall' => 'same as `php cast engine:editor uninstall`',
    ];

    public function handle(Input $input, Output $output): int
    {
        $argv = ['editor:install', $input->hasOption('uninstall') ? 'uninstall' : 'install'];
        $editor = $input->option('editor');
        if (is_string($editor) && $editor !== '') $argv[] = '--editor=' . $editor;

        return (new EngineCommand($this->app, 'editor'))->handle(new Input($argv), $output);
    }
}
