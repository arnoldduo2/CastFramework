<?php

declare(strict_types=1);

if (!function_exists('__round')) {
    /** Round and format with fixed decimals: 105 => "105.00". */
    function __round(int|float|string $value, int $precision = 2): string
    {
        return number_format(round((float) $value, $precision), $precision, '.', '');
    }
}

if (!function_exists('__floats')) {
    /** Are two numbers equal within `$precision` decimals? */
    function __floats(int|float|string $value1, int|float|string $value2, int $precision = 5): bool
    {
        return abs((float) $value1 - (float) $value2) < 10 ** -$precision;
    }
}

if (!function_exists('__compare')) {
    /** Returns `$returnValue` when the comparison holds, otherwise ''. */
    function __compare(mixed $value1, mixed $value2, string $returnValue = 'true', string $comparator = '==='): string
    {
        return match ($comparator) {
            '===' => $value1 === $value2,
            '!==' => $value1 !== $value2,
            '==' => $value1 == $value2,
            '!=' => $value1 != $value2,
            '>=' => $value1 >= $value2,
            '<=' => $value1 <= $value2,
            '>' => $value1 > $value2,
            '<' => $value1 < $value2,
        } ? $returnValue : '';
    }
}

if (!function_exists('isMultiple')) {
    /** Is a positive number an exact multiple of `$num`? */
    function isMultiple(int|float $value, int $num = 1000): bool
    {
        return $value > 0 && (int) $value % $num === 0;
    }
}

if (!function_exists('getFloat')) {
    /** "1,234.50" => 1234.5 (spaces and thousand separators removed). */
    function getFloat(mixed $str): float
    {
        $str = str_replace([' ', ','], '', (string) $str);
        return (float) $str;
    }
}

if (!function_exists('__money')) {
    /**
     * Format an amount with a currency symbol: `__money(1234.5, '$')` => "$ 1,234.50"; negatives are shown in
     * brackets: "($ 12.00)". The number format per currency can be set in `config('money.formats')`
     * (`['EUR' => [2, ',', '.']]` = decimals, decimal point, thousands separator); default 2, '.', ','.
     */
    function __money(float|string $amount, string $format = '$', bool $space = true): string
    {
        $codes = ['$' => 'USD', 'US$' => 'USD', 'Z$' => 'ZWL', '€' => 'EUR', '£' => 'GBP', 'R' => 'ZAR', 'ZIG' => 'ZWG'];
        $code = str_replace(' ', '', $codes[$format] ?? $format);
        [$decimals, $point, $thousands] = (array) (config('money.formats', [])[$code] ?? [2, '.', ',']);

        $money = number_format(abs((float) $amount), (int) $decimals, (string) $point, (string) $thousands);
        $text = $format . ($space ? ' ' : '') . $money;
        return (float) $amount < 0 ? "($text)" : $text;
    }
}

if (!function_exists('__symbolsCurr')) {
    /** Currency code => symbol ("USD" => "US$"). Extend or replace with `config('money.symbols')`. */
    function __symbolsCurr(string $cur): string
    {
        $symbols = (array) config('money.symbols', []) + ['USD' => 'US$', 'EUR' => '€', 'GBP' => '£', 'ZAR' => 'R', 'ZWL' => 'Z$', 'ZIG' => 'ZWG', 'ZWG' => 'ZWG'];
        return $symbols[$cur] ?? '#';
    }
}
