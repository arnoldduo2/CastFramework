<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `php cast editor:install`: copies the VS Code extension that ships with the framework (`editor/vscode`: highlighting for
 * .cast.php views, Ctrl+click on components, snippets) into the extensions folder of the editors found on this machine.
 */
final class EditorInstallCommand extends Command
{
    protected string $name = 'editor:install';
    protected string $description = 'Install the VS Code extension for .cast.php views (highlighting, Ctrl+click, props)';
    protected array $options = [
        '--editor=NAME' => 'Only this editor: code, insiders, vscodium, cursor, antigravity or windsurf (default: every editor found)',
        '--dir=PATH' => 'Install into this extensions folder instead',
        '--uninstall' => 'Remove the extension',
    ];
    protected array $examples = [
        'php cast editor:install' => 'every editor found',
        'php cast editor:install --editor=code' => 'VS Code only',
        'php cast editor:install --uninstall' => 'remove it',
    ];

    /** editor => folder below the home directory */
    private const EDITORS = [
        'code' => '.vscode/extensions',
        'insiders' => '.vscode-insiders/extensions',
        'vscodium' => '.vscode-oss/extensions',
        'cursor' => '.cursor/extensions',
        'antigravity' => '.antigravity/extensions',
        'windsurf' => '.windsurf/extensions',
    ];

    private const ID = 'anode.cast-framework';

    public function handle(Input $input, Output $output): int
    {
        $source = dirname(__DIR__, 3) . '/editor/vscode';
        $manifest = $source . '/package.json';
        if (!is_file($manifest)) {
            $output->error('The extension is not part of this installation (editor/vscode is missing). Reinstall the package.');
            return 1;
        }
        $version = (string) (json_decode((string) file_get_contents($manifest), true)['version'] ?? '0.0.0');

        $targets = $this->targets($input, $output);
        if ($targets === null) return 1;

        $uninstall = $input->hasOption('uninstall');
        foreach ($targets as $label => $dir) {
            $removed = $this->removeOld($dir);
            if ($uninstall) {
                $output->info(($removed ? 'removed   ' : 'not installed  ') . $label);
                continue;
            }
            $this->copy($source, $dir . DIRECTORY_SEPARATOR . self::ID . '-' . $version);
            $output->info("installed $label  (" . self::ID . "-$version)");
        }

        $output->line();
        $output->line($uninstall ? 'Reload the editor window to finish.' : 'Reload the window (Developer: Reload Window) and open a .cast.php file. Ctrl+click a <Component /> to open its file.');
        return 0;
    }

    /** @return array<string, string>|null label => extensions folder */
    private function targets(Input $input, Output $output): ?array
    {
        $dir = $input->option('dir');
        if (is_string($dir) && $dir !== '') {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                $output->error("Cannot use $dir.");
                return null;
            }
            return [$dir => rtrim($dir, '/\\')];
        }

        $home = $this->home();
        if ($home === null) {
            $output->error('Cannot find your home folder. Use --dir=PATH with the editor\'s extensions folder.');
            return null;
        }

        $wanted = $input->option('editor');
        if (is_string($wanted) && $wanted !== '') {
            if (!isset(self::EDITORS[$wanted])) {
                $output->error('Unknown editor "' . $wanted . '". Use one of: ' . implode(', ', array_keys(self::EDITORS)) . '.');
                return null;
            }
            $path = $home . '/' . self::EDITORS[$wanted];
            if (!is_dir($path) && !@mkdir($path, 0775, true)) {
                $output->error("Cannot create $path.");
                return null;
            }
            return [$wanted => $path];
        }

        $found = [];
        foreach (self::EDITORS as $editor => $relative) {
            if (is_dir($home . '/' . $relative)) $found[$editor] = $home . '/' . $relative;
        }
        if (!$found) {
            $output->error('No VS Code style editor was found in ' . $home . '. Start the editor once, or use --editor=code, or --dir=PATH.');
            return null;
        }
        return $found;
    }

    private function home(): ?string
    {
        foreach (['CAST_HOME', 'USERPROFILE', 'HOME'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '' && is_dir($value)) return rtrim($value, '/\\');
        }
        return null;
    }

    /** Remove earlier versions of the extension from an extensions folder. */
    private function removeOld(string $dir): bool
    {
        $removed = false;
        foreach (glob($dir . DIRECTORY_SEPARATOR . self::ID . '-*', GLOB_ONLYDIR) ?: [] as $old) {
            $this->delete($old);
            $removed = true;
        }
        return $removed;
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0775, true);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
            if (preg_match('#^(node_modules|test)(/|$)|^package-lock\.json$|\.vsix$#', $relative)) continue;

            $target = $to . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function delete(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
