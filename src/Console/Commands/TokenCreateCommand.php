<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Contracts\UserProvider;
use Cast\Services\ApiTokens;
use Cast\Services\Auth;

final class TokenCreateCommand extends Command
{
    protected string $name = 'token:create';
    protected string $description = 'Create an API token for a user: token:create <login> [--name=] [--abilities=a,b] [--days=N]';

    public function handle(Input $input, Output $output): int
    {
        $login = (string) $input->argument(0, '');
        if ($login === '') {
            $output->error('Usage: php cast token:create <login> [--name="my app"] [--abilities=items:read,items:write] [--days=30]');
            return 1;
        }

        $auth = $this->app->has('auth') ? $this->app->make('auth') : null;
        $provider = $auth instanceof Auth ? $auth->provider() : null;
        if (!$provider instanceof UserProvider) {
            $output->error('No "auth" service is bound, so users cannot be looked up.');
            return 1;
        }

        $user = $provider->findByCredentials($login);
        if (!$user || !isset($user['id'])) {
            $output->error("No user found for \"$login\".");
            return 1;
        }

        $abilities = array_values(array_filter(array_map('trim', explode(',', (string) $input->option('abilities', '*')))));
        $days = (int) $input->option('days', 0);

        /** @var ApiTokens $tokens */
        $tokens = $this->app->make('tokens');
        $issued = $tokens->issue($user['id'], (string) $input->option('name', 'cli'), $abilities ?: ['*'], $days > 0 ? $days * 86400 : null);

        $output->info('Token created. Copy it now: it cannot be shown again.');
        $output->line();
        $output->line($issued['token']);
        $output->line();
        $output->line('Use it as:  Authorization: Bearer ' . $issued['token']);
        $output->line('Revoke it:  php cast token:revoke ' . $issued['id']);
        return 0;
    }
}
