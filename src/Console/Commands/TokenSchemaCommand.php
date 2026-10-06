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
    protected string $description = 'Print the SQL for the api_tokens table, or create it with --run';

    public function handle(Input $input, Output $output): int
    {
        $driver = (string) Config::get('database.driver', 'mysql');
        $sql = ApiTokenSchema::sql($driver, (string) Config::get('api.tokens.table', 'api_tokens'));

        if (!$input->hasOption('run')) {
            $output->line($sql . ';');
            return 0;
        }

        Database::connection()->exec($sql);
        $output->info('The api_tokens table exists.');
        return 0;
    }
}
