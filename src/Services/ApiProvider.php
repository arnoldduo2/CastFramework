<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\App\ServiceProvider;
use Cast\Contracts\TokenStore;
use Cast\Core\Config;

/** Binds the token store (`token_store`) and the token service (`tokens`). Nothing touches the database until a token is used. */
final class ApiProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('token_store', fn() => new DatabaseTokenStore((string) Config::get('api.tokens.table', 'api_tokens')));
        $this->app->singleton(TokenStore::class, fn() => $this->app->make('token_store'));
        $this->app->singleton('tokens', fn() => new ApiTokens($this->app->make('token_store')));
    }
}
