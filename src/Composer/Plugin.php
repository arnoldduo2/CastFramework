<?php

declare(strict_types=1);

namespace Cast\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Part of the framework: after `composer require anode/cast-framework` (and every install/update) it makes sure the
 * project root has the `cast` launcher, so `php cast <command>` works straight away. An existing `cast` file is never touched.
 * (If Composer's plugins are not allowed for this package, `php vendor/bin/cast init` creates the same file.)
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'launcher',
            ScriptEvents::POST_UPDATE_CMD => 'launcher',
        ];
    }

    public function launcher(Event $event): void
    {
        $vendor = (string) $event->getComposer()->getConfig()->get('vendor-dir');
        $root = dirname($vendor);
        $target = $root . DIRECTORY_SEPARATOR . 'cast';
        $stub = dirname(__DIR__) . '/Stubs/init/cast.stub';

        $io = $event->getIO();
        if (!is_file($target) && is_file($stub) && @file_put_contents($target, (string) file_get_contents($stub)) !== false) {
            @chmod($target, 0755);
            $io->write('<info>CastFramework:</info> created the <comment>cast</comment> console launcher. Try:  php cast list');
            // said once, with the launcher (nothing is installed outside the project unless you run the command)
            $io->write('<info>CastFramework:</info> VS Code highlighting and Ctrl+click for .cast.php views:  <comment>php cast editor:install</comment>');
        }
    }
}
