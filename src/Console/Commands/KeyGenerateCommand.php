<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Env;
use Cast\Support\Crypt;

/** `php cast key:generate`: makes the app key (`APP_KEY` in .env) used by sign(), encrypt() and friends. */
final class KeyGenerateCommand extends Command
{
    protected string $name = 'key:generate';
    protected string $description = 'Generate the app key (APP_KEY in .env)';
    protected array $options = [
        '--show' => 'Print a new key instead of writing it to .env',
        '--force' => 'Replace an existing key (signed links and encrypted values made with the old key stop working)',
    ];
    protected array $examples = [
        'php cast key:generate' => 'write APP_KEY if there is none',
        'php cast key:generate --show' => 'just print one',
        'php cast key:generate --force' => 'rotate the key',
    ];

    public function handle(Input $input, Output $output): int
    {
        $key = Crypt::generateKey();
        if ($input->hasOption('show')) {
            $output->line($key);
            return 0;
        }
        if (!is_file($this->app->basePath('.env'))) {
            $output->error('There is no .env file here. Create it first (php cast init), or use --show and put the key where you keep your settings.');
            return 1;
        }
        if ((string) Env::get('APP_KEY', '') !== '' && !$input->hasOption('force')) {
            $output->error('APP_KEY is already set. Changing it invalidates signed links and encrypted values made with it. Use --force to replace it.');
            return 1;
        }
        Env::set('APP_KEY', $key);
        $output->info('APP_KEY was written to .env.');
        return 0;
    }
}
