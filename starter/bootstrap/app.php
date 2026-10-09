<?php

declare(strict_types=1);

use Cast\App\Application;

/**
 * Creates the application. Used by public/index.php (web requests) and by `php cast` (the console), so both see the same app.
 * The folder given here is the project root: .env, config/, routes/, resources/ and storage/ are found relative to it.
 *
 * Only change this to move folders. By default the app expects  config/  routes/  resources/views  resources/  storage/  database/  public/ ; to
 * move one, pass the new location, relative to the project root, for example:
 *     new Application(dirname(__DIR__), ['paths' => ['views' => 'src/resources/views', 'resources' => 'src/resources', 'config' => 'src/config']]);
 * (`php cast init` writes this line for you when the views, css and js live inside the source folder.)
 */
return new Application(dirname(__DIR__));
