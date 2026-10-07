<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;
use Cast\Core\Database;
use Cast\Services\ApiTokenSchema;

final class TokenSchemaCommand extends Command
{
    protected string $name = 'token:schema';
    protected string $description = 'The api_tokens table: print its SQL, write a migration, or create it';
    protected array $options = [
        '--migration' => 'Write a migration for it into database/migrations',
        '--run' => 'Create the table now',
    ];
    protected array $examples = [
        'php cast token:schema' => 'print the SQL',
        'php cast token:schema --migration' => 'a migration file',
    ];

    public function handle(Input $input, Output $output): int
    {
        $driver = (string) Config::get('database.driver', 'mysql');
        $sql = ApiTokenSchema::sql($driver, (string) Config::get('api.tokens.table', 'api_tokens'));

        if ($input->hasOption('migration')) {
            $dir = $this->app->databasePath('migrations');
            $existing = glob($dir . DIRECTORY_SEPARATOR . '*_create_' . Config::get('api.tokens.table', 'api_tokens') . '_table.php') ?: [];
            if ($existing) {
                $output->warn('A migration for the tokens table already exists: ' . basename($existing[0]));
                return 0;
            }
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $table = (string) Config::get('api.tokens.table', 'api_tokens');
            $file = $dir . DIRECTORY_SEPARATOR . date('Y_m_d_His') . '_create_' . $table . '_table.php';
            file_put_contents($file, ApiTokenSchema::migration($table));
            $output->info('Created database/migrations/' . basename($file) . '. Run:  php cast migrate');
            return 0;
        }

        if (!$input->hasOption('run')) {
            $output->line($sql . ';');
            return 0;
        }

        Database::connection()->exec($sql);
        $output->info('The api_tokens table exists.');
        return 0;
    }
}
