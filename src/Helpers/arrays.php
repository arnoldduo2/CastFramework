<?php

declare(strict_types=1);

if (!function_exists('arrayToList')) {
    /** [1, [2, 3], 4] => "1,2,3,4" */
    function arrayToList(array $arr): string
    {
        $list = '';
        foreach ($arr as $item) {
            $list .= (is_array($item) ? arrayToList($item) : $item) . ',';
        }
        return rtrim($list, ',');
    }
}

if (!function_exists('__implode')) {
    /** Join array values (optionally `key=value` pairs) into a string with a separator and a prefix on each item. */
    function __implode(array $array, bool $withKey = true, string $separator = ',', string $prefix = ''): string
    {
        $parts = [];
        foreach ($array as $key => $value) {
            $parts[] = $prefix . ($withKey ? "$key=$value" : $value);
        }
        return implode($separator, $parts);
    }
}

if (!function_exists('arraySearch')) {
    /** Is `$needle` one of the values? (strict by default) */
    function arraySearch(string $needle, array $haystack, bool $strict = true): bool
    {
        return in_array($needle, $haystack, $strict);
    }
}

if (!function_exists('searchMultiArray')) {
    /** Does any row have `$needle` in the given column? */
    function searchMultiArray(string $needle, array $haystack, string|int $columnKey): bool
    {
        return in_array($needle, array_column($haystack, $columnKey), true);
    }
}

if (!function_exists('arrayUnique')) {
    /** Unique values, re-indexed. Works for arrays of arrays too. */
    function arrayUnique(array $data): array
    {
        return array_values(array_map('unserialize', array_unique(array_map('serialize', $data))));
    }
}

if (!function_exists('arrayRand')) {
    /** One random value, or several with `$amount > 1`. */
    function arrayRand(array $array, int $amount = 1): mixed
    {
        if (!$array) return $amount > 1 ? [] : '';
        $keys = (array) array_rand($array, min($amount, count($array)));
        $values = array_map(fn($k) => $array[$k], $keys);
        return $amount > 1 ? $values : $values[0];
    }
}

if (!function_exists('arrayMultiToSingle')) {
    /** Rows of arrays => one list of a column's values. */
    function arrayMultiToSingle(array $multi2dArray, string $key): array
    {
        return array_column($multi2dArray, $key);
    }
}

if (!function_exists('arrayReducer')) {
    /** [['name' => 'a', 'value' => 1]] => ['a' => 1] */
    function arrayReducer(array $array, string $keyName = 'name', string $value = 'value'): array
    {
        $data = [];
        foreach ($array as $row) $data[$row[$keyName]] = $row[$value];
        return $data;
    }
}

if (!function_exists('add2dArray')) {
    /** Sum `$valueKey` for rows that share the same `$matchKey`: returns [matchValue => total]. */
    function add2dArray(array $data, string $matchKey = 'depart_id', string $valueKey = 'total'): array
    {
        $totals = [];
        foreach ($data as $row) {
            $totals[$row[$matchKey]] = ($totals[$row[$matchKey]] ?? 0) + (float) $row[$valueKey];
        }
        return $totals;
    }
}

if (!function_exists('sortArray')) {
    /** Sort rows by a column. */
    function sortArray(array $data, string|int|null $column = null, int $direction = SORT_ASC): array
    {
        $sort = array_column($data, $column);
        array_multisort($sort, $direction, $data);
        return $data;
    }
}

if (!function_exists('sortMultiArray')) {
    /** Sort rows by a column holding dates, numbers or text (sorted in place and returned). */
    function sortMultiArray(array &$data, string $column, int $direction = SORT_ASC): array
    {
        $reference = [];
        foreach ($data as $k => $row) {
            $value = $row[$column];
            // dates (anything with letters, dashes, slashes or colons) sort by time, plain numbers by value, the rest as text
            $reference[$k] = is_string($value) && preg_match('/[-\/:a-zA-Z]/', $value) && ($time = strtotime($value)) !== false
                ? $time
                : (is_numeric($value) ? (float) $value : $value);
        }
        array_multisort($reference, $direction, $data);
        return $data;
    }
}

if (!function_exists('paginateArray')) {
    /** @return array{0: array, 1: int} The items split into pages of `$len`, and the page count. */
    function paginateArray(array $items, int $len = 10): array
    {
        $len = max(1, $len);
        if (count($items) <= $len) return [$items, 1];
        $pages = array_chunk($items, $len);
        return [$pages, count($pages)];
    }
}

if (!function_exists('parseArray')) {
    /** Decode JSON strings found in a value or inside an array (recursively). */
    function parseArray(mixed $data): mixed
    {
        if (jsonValidate($data)) return json_decode($data, true);
        if (!is_array($data)) return $data;

        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = is_array($v) ? parseArray($v) : (jsonValidate($v) ? json_decode($v, true) : $v);
        }
        return $out;
    }
}

if (!function_exists('decodeJsonInArray')) {
    /** Decode the JSON stored in `$data[$key]`, optionally returning one key of it. */
    function decodeJsonInArray(array|bool|null $data, string $key, ?string $value = null): mixed
    {
        if (!is_array($data) || !isset($data[$key])) return '';
        $array = json_decode($data[$key], true);
        return $value !== null && isset($array[$value]) ? $array[$value] : ($array ?? '');
    }
}
