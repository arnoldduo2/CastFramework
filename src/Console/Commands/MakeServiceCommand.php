<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output, Prompt};
use Cast\Core\Config;

/**
 * `php cast make:service Name [--example=printer|barcode|qrcode]`: a service class in <source>/Services. A service holds logic that is not a page or a table.
 * The examples are working starting points for things older (ERP) apps need: ESC/POS receipt printing, Code 128 barcodes, QR codes.
 */
final class MakeServiceCommand extends Command
{
    protected string $name = 'make:service';
    protected string $description = 'Create a service class (blank, or a printer, barcode or QR code example)';
    protected array $arguments = ['Name' => 'The service name, e.g. Printer (the suffix Service is added when missing)'];
    protected array $options = [
        '--example=KIND' => 'plain (blank), printer (ESC/POS receipt printer), barcode (Code 128 as SVG) or qrcode (QR codes). Asked when left out',
        '--force' => 'Overwrite the file if it exists',
    ];
    protected array $examples = [
        'php cast make:service Mailer' => 'a blank service with a how-to in its comment',
        'php cast make:service Receipt --example=printer' => 'ESC/POS printing to a network or USB thermal printer',
        'php cast make:service Barcode --example=barcode' => 'Code 128 barcodes as SVG, no library',
        'php cast make:service Qr --example=qrcode' => 'QR codes through chillerlan/php-qrcode',
    ];

    private const EXAMPLES = [
        'plain' => 'a blank service',
        'printer' => 'receipt/label printer over the network or USB (ESC/POS)',
        'barcode' => 'Code 128 barcodes as SVG (no library)',
        'qrcode' => 'QR codes as SVG or PNG (needs composer require chillerlan/php-qrcode)',
    ];

    public function handle(Input $input, Output $output): int
    {
        $given = $input->argument(0);
        if ($given === null || !preg_match('/^[A-Za-z][A-Za-z0-9_\/\\\\]*$/', $given)) {
            $output->error('Usage: php cast make:service <Name> [--example=printer|barcode|qrcode]');
            return 1;
        }
        $kind = (string) $input->option('example');
        if ($kind === '') $kind = (new Prompt($output, Prompt::canAsk($input)))->choice('Start from', self::EXAMPLES, 'plain');
        if (!isset(self::EXAMPLES[$kind])) {
            $output->error('--example must be one of: ' . implode(', ', array_keys(self::EXAMPLES)));
            return 1;
        }

        $parts = preg_split('#[/\\\\]#', $given);
        $base = $this->studly(array_pop($parts));
        $class = str_ends_with($base, 'Service') ? $base : $base . 'Service';
        $sub = array_map([$this, 'studly'], $parts);
        $root = (string) Config::get('app.namespace', 'App');
        $namespace = implode('\\', [$root, 'Services', ...$sub]);
        $path = $this->app->basePath((string) Config::get('app.source_path', 'app') . '/Services' . ($sub ? '/' . implode('/', $sub) : '') . "/$class.php");
        if (is_file($path) && !$input->hasOption('force')) {
            $output->error("$path already exists (use --force to overwrite).");
            return 1;
        }

        $stub = (string) file_get_contents(dirname(__DIR__, 2) . "/Stubs/services/$kind.stub");
        $key = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', preg_replace('/Service$/', '', $class)));
        $code = strtr($stub, ['{namespace}' => $namespace, '{class}' => $class, '{key}' => $key]);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, $code);

        $output->info("Created $namespace\\$class");
        $output->line("  $path");
        $output->line();
        $output->line('Share one instance across the app by binding it in your AppServiceProvider::register():');
        $output->line($output->color("    \$this->app->singleton('$key', fn() => new \\$namespace\\$class());", 'green'));
        $output->line("then use  app('$key')  in controllers and views.");
        if ($kind === 'qrcode') $output->warn('This one needs a library:  composer require chillerlan/php-qrcode');
        return 0;
    }

    private function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }
}
