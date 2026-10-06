<?php

declare(strict_types=1);

use Cast\App\Application;

// Used by public/index.php and by `php vendor/bin/cast`.
return new Application(dirname(__DIR__));
