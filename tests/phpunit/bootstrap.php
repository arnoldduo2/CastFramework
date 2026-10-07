<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. The cases in tests/cases/*.php are written as `test('name', fn)` with small helpers (eq, ok, has,
 * throws...). This loads every file in order, in "collect" mode, so PHPUnit can run each registered case as its own test
 * (see CastCasesTest). The cross-file helper functions the cases share are defined once, as with tests/run.php.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/support.php';

$GLOBALS['cast_collect'] = [];
foreach (glob(dirname(__DIR__) . '/cases/*.php') ?: [] as $file) {
    $GLOBALS['cast_file'] = basename($file, '.php');
    require $file;
}
$collected = $GLOBALS['cast_collect'];
unset($GLOBALS['cast_collect']);   // from here on, test() is never called by cases: PHPUnit runs the closures

$GLOBALS['cast_cases'] = $collected;
