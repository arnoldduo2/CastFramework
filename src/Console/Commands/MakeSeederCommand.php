<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};

final class MakeSeederCommand extends Command
{
    protected string $name = 'make:seeder';
    protected string $description = 'Create a seeder in database/seeders: make:seeder UserSeeder';

    public function handle(Input $input, Output $output): int
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $input->argument(0, ''));
        if ($name === '' || !preg_match('/^[A-Za-z]/', $name)) {
            $output->error('Usage: php cast make:seeder <Name>   e.g. UserSeeder');
            return 1;
        }
        $name = ucfirst($name);
        $file = $this->app->databasePath('seeders/' . $name . '.php');
        if (is_file($file) && !$input->hasOption('force')) {
            $output->error("database/seeders/$name.php already exists. Use --force to overwrite.");
            return 1;
        }
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0775, true);

        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

namespace Database\\Seeders;

use Cast\\Database\\Seeder;

class $name extends Seeder
{
    public function run(): void
    {
        //
    }
}

PHP);
        $output->info("Created database/seeders/$name.php");
        return 0;
    }
}
