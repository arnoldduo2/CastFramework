<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;
use Cast\Core\Modules\DatabaseModuleStore;

/** `php cast modules:table`: the table behind  'store' => 'database'  in config/modules.php (module switches). */
final class ModulesTableCommand extends Command
{
    protected string $name = 'modules:table';
    protected string $description = 'Write the migration for the modules table (database-controlled modules)';
    protected array $options = [
        '--migration' => 'Write the migration into database/migrations (without it, only explains)',
    ];
    protected array $examples = [
        'php cast modules:table --migration' => 'then  php cast migrate  and  \'store\' => \'database\'  in config/modules.php',
    ];

    public function handle(Input $input, Output $output): int
    {
        $table = (string) Config::get('modules.table', 'modules');
        if (!$input->hasOption('migration')) {
            $output->line('One row per module: name, enabled (1 on, 0 off, NULL follows the config).');
            $output->line('Run with --migration to write the migration, then  php cast migrate,  and set  \'store\' => \'database\'  in config/modules.php.');
            return 0;
        }
        $dir = $this->app->databasePath('migrations');
        if (glob("$dir/*_create_{$table}_table.php")) {
            $output->warn("A migration for the $table table already exists.");
            return 0;
        }
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = "$dir/" . date('Y_m_d_His') . "_create_{$table}_table.php";
        file_put_contents($file, DatabaseModuleStore::migration($table));
        $output->info('Created database/migrations/' . basename($file) . '. Run:  php cast migrate');
        $output->line("Then set  'store' => 'database'  in config/modules.php: switches are read from the $table table (an admin screen can write to it).");
        return 0;
    }
}
