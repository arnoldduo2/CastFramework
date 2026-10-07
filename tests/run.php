<?php

declare(strict_types=1);

// Dependency-free test runner:  php tests/run.php [filter]

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support.php';

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/cases/*.php') as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) continue;
    echo "\n" . basename($file, '.php') . "\n";
    $GLOBALS['cast_file'] = basename($file, '.php');
    require $file;
}

if (!empty($GLOBALS['failed'])) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['failed'] as $name) echo "  - $name\n";
}
echo $GLOBALS['failures'] ? "\n{$GLOBALS['failures']} failed of {$GLOBALS['total']}\n" : "\nall {$GLOBALS['total']} passed\n";
exit($GLOBALS['failures'] ? 1 : 0);
