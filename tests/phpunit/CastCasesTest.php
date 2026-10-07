<?php

declare(strict_types=1);

namespace Cast\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every case of tests/cases/*.php as one PHPUnit test, named "<file>: <case name>":
 *
 *   vendor/bin/phpunit                          all of them
 *   vendor/bin/phpunit --filter migrations      the cases whose name contains "migrations"
 *   vendor/bin/phpunit --testdox                one readable line per case
 */
final class CastCasesTest extends TestCase
{
    /** @return iterable<string, array{callable}> */
    public static function cases(): iterable
    {
        $seen = [];
        foreach ($GLOBALS['cast_cases'] ?? [] as [$file, $name, $fn]) {
            $key = "$file: $name";
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > 1) $key .= ' #' . $seen[$key];
            yield $key => [$fn];
        }
    }

    #[DataProvider('cases')]
    public function testCase(callable $case): void
    {
        reset_state();   // each case starts from a clean app, config, router and session
        try {
            $case();
        } catch (\AssertionError $e) {
            // the helpers (eq, ok, has...) throw PHP's AssertionError: report it as a test failure, not an error
            throw new AssertionFailedError($e->getMessage(), 0, $e);
        }
        $this->addToAssertionCount(1);
    }
}
