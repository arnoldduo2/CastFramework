<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Core\Router;

final class RouteListCommand extends Command
{
    protected string $name = 'route:list';
    protected string $description = 'List every registered route with its verbs and middleware';
    protected array $examples = [
        'php cast route:list' => '',
    ];

    public function handle(Input $input, Output $output): int
    {
        $rows = [];
        foreach (Router::getRoutes() as $route) {
            $handler = $route->handler;
            $handler = match (true) {
                $handler instanceof \Closure => 'Closure',
                is_array($handler) => (is_string($handler[0]) ? $handler[0] : get_debug_type($handler[0])) . '@' . ($handler[1] ?? 'index'),
                is_string($handler) => str_contains($handler, '::') ? str_replace('::', '@', $handler) : $handler . '@index',
                default => get_debug_type($handler),
            };
            $notes = [];
            foreach ($route->middleware as $spec) $notes[] = basename(str_replace('\\', '/', (string) $spec[0])) . (isset($spec[1]) ? ':' . $spec[1] : '');
            if ($route->permissions) $notes[] = 'can:' . implode('|', $route->permissions);
            $rows[] = [$route->method, $route->path, $handler, implode(', ', $notes)];
        }
        usort($rows, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        if (!$rows) {
            $output->warn('No routes are registered.');
            return 0;
        }
        $output->table(['Method', 'Path', 'Handler', 'Middleware'], $rows);
        return 0;
    }
}
