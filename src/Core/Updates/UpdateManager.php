<?php

declare(strict_types=1);

namespace Cast\Core\Updates;

use Cast\App\Application;
use Cast\Contracts\Updater;
use Cast\Core\Config;
use Cast\Http\HttpException;

/**
 * If the app binds an `updater` ({@see Updater}) and it needs an update, run it (when `app.auto_update` is true)
 * or show the "updating" page.
 */
final class UpdateManager
{
    public static function check(Application $app): void
    {
        if (!$app->has('updater')) return;
        $updater = $app->make('updater');
        if (!$updater instanceof Updater || !$updater->needsUpdate()) return;

        if (Config::get('app.auto_update', false) && $updater->run() && !$updater->needsUpdate()) return;

        throw new HttpException(503, 'The system is being updated. Please try again in a moment.', ['Retry-After' => '30'], 'updating', [
            'from' => $updater->currentVersion(),
            'to' => $updater->latestVersion(),
        ]);
    }
}
