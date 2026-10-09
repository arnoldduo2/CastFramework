<?php

declare(strict_types=1);

if (!function_exists('__ucwords')) {
    /** "first_name" => "First Name" (underscores and dashes become spaces unless `$removeDashes` is false). */
    function __ucwords(?string $str, bool $removeDashes = true): string
    {
        return $str && $removeDashes
            ? ucwords(str_replace('-', ' ', str_replace('_', ' ', $str)))
            : ($str ? ucwords($str) : '');
    }
}

if (!function_exists('__ucfirst')) {
    function __ucfirst(?string $str): string
    {
        return $str ? ucfirst($str) : '';
    }
}

if (!function_exists('str_capitalize')) {
    /** Capitalise each word, keeping dashes and other symbols; `$lineSeparator` splits text into separately handled parts. */
    function str_capitalize(string $str, string $lineSeparator = '\r', bool $preserveSymbols = true): string
    {
        if ($str === '') return $str;

        $parts = explode($lineSeparator, $str);
        foreach ($parts as &$part) {
            if ($preserveSymbols) {
                $words = preg_split('/(\s+|_+|\.+)/', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($words as &$word) {
                    if (!preg_match('/^[\s_.]*$/', $word)) $word = ucfirst(strtolower($word));
                }
                unset($word);
                $part = implode('', $words);
            } else {
                $part = __ucfirst(__ucwords($part));
            }
        }
        unset($part);
        return implode($lineSeparator, $parts);
    }
}

if (!function_exists('snakeCase')) {
    /** "JournalEntries" => "journal_entries" */
    function snakeCase(string $str): string
    {
        return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $str));
    }
}

if (!function_exists('htchars')) {
    /** Escape for HTML output (quotes included, invalid UTF-8 substituted). */
    function htchars(mixed $str): string
    {
        // null (an empty column, a prop that was not given) is an empty string; numbers and Stringables are text
        return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('str_escape')) {
    /** Make a multi-line string safe for a single-line value: newlines become `\n`, or are removed with `$clear`. */
    function str_escape(?string $str, bool $clear = false): string
    {
        if (!$str) return '';
        if (preg_match('/\R/', $str)) {
            $str = $clear ? preg_replace('/\R/', '', $str) : preg_replace('/\R/', '\\n', $str);
        }
        return $str;
    }
}

if (!function_exists('htmlNewLine')) {
    /** Turn newlines (and literal "\n" sequences) into `<br>`. */
    function htmlNewLine(string $str, string $lineSeparator = '<br>'): string
    {
        $str = str_replace(["\\r\\n", "\\r", "\\n"], $lineSeparator, $str);
        return preg_replace('/\r\n|\r|\n/', $lineSeparator, $str);
    }
}

if (!function_exists('strReplace')) {
    function strReplace(array|string|int $search, array|string|int $replace, array|string|int $subject): array|string|int
    {
        return str_replace($search, $replace, $subject);
    }
}

if (!function_exists('str_addHyphen')) {
    /** "ABC-X" style: replaces `$subStr` inside the text by a hyphen and appends it ("a/b" + "/" => "a-b/"). */
    function str_addHyphen(string $str, string $subStr): string
    {
        return str_contains($str, $subStr) ? str_replace($subStr, '-', $str) . $subStr : $str;
    }
}

if (!function_exists('__getSplitStr')) {
    /** "John Paul Smith" => ["John", " Paul Smith"] */
    function __getSplitStr(string $names, string $separator = ' '): array
    {
        $first = '';
        $rest = '';
        foreach (explode($separator, $names) as $n => $name) {
            if ($n === 0) $first = $name;
            else $rest .= " $name";
        }
        return [$first, $rest];
    }
}
