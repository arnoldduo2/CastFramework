<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\Console\{Command, Input, Output};
use Cast\Services\ApiTokens;

final class TokenRevokeCommand extends Command
{
    protected string $name = 'token:revoke';
    protected string $description = 'Revoke an API token';
    protected array $arguments = [
        'id' => 'The id: the part of the token before the "|"',
    ];
    protected array $examples = [
        'php cast token:revoke 12' => '',
    ];

    public function handle(Input $input, Output $output): int
    {
        $id = (string) $input->argument(0, '');
        if ($id === '') {
            $output->error('Usage: php cast token:revoke <token-id>');
            return 1;
        }

        /** @var ApiTokens $tokens */
        $tokens = $this->app->make('tokens');
        if (!$tokens->revoke(explode('|', $id)[0])) {
            $output->error('No active token with that id.');
            return 1;
        }
        $output->info('Token revoked.');
        return 0;
    }
}
