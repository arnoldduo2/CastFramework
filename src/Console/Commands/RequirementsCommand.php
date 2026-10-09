<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Support\Requirements;

/** `php cast requirements`: does this PHP have what the framework (and production) needs? */
final class RequirementsCommand extends Command
{
    protected string $name = 'requirements';
    protected string $description = 'Check this PHP for the extensions and settings the framework needs';
    protected array $options = [
        '--production' => 'Also check the settings a live server wants (display_errors off, OPcache, strict sessions ...)',
        '--json' => 'Print the results as JSON',
    ];
    protected array $examples = [
        'php cast requirements' => 'the PHP version, extensions and limits',
        'php cast requirements --production' => 'plus what a live server should have',
        'php cast requirements --json' => 'for scripts and CI (exit code 1 when something required is missing)',
    ];

    public function handle(Input $input, Output $output): int
    {
        $results = Requirements::check((string) config('database.driver', ''), $input->hasOption('production'));
        if ($input->hasOption('json')) {
            $output->line((string) json_encode(array_map(fn($r) => ['status' => $r[0], 'check' => $r[1], 'fix' => $r[2]], $results), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Requirements::failures($results) === 0 ? 0 : 1;
        }
        $output->line('php.ini in use: ' . (php_ini_loaded_file() ?: 'none'));
        foreach ($results as [$status, $label, $fix]) {
            match ($status) {
                'ok' => $output->info("  ok    $label"),
                'warn' => $output->warn("  note  $label" . ($fix !== '' ? " ($fix)" : '')),
                default => $output->error("  FAIL  $label" . ($fix !== '' ? " ($fix)" : '')),
            };
        }
        $fails = Requirements::failures($results);
        $output->line();
        $fails === 0 ? $output->info('This PHP can run the framework.') : $output->error("$fails required item(s) missing.");
        return $fails === 0 ? 0 : 1;
    }
}
