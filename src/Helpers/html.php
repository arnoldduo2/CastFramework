<?php

declare(strict_types=1);

use Cast\Core\Session;

if (!function_exists('jsonQuotes')) {
    /**
     * JSON that is safe inside a double-quoted HTML attribute: every `"` becomes `&#39;`. The browser turns it back into `'`,
     * and the front end reads it with `app.objectValidate(value, true)`.
     */
    function jsonQuotes(array $data, bool $braces = false): string
    {
        $str = json_encode($data);
        if ($braces) $str = str_replace(['[', ']'], '', $str);
        $str = str_replace('&#39', "\\'", $str);
        return str_replace('"', '&#39;', $str);
    }
}

if (!function_exists('jsonValidate')) {
    function jsonValidate(mixed $value): bool
    {
        if (!is_string($value)) return false;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

if (!function_exists('__attr')) {
    /** `['id' => 'x', 'data-a' => 'b']` => " id='x' data-a='b'" (a string is returned with a leading space). */
    function __attr(array|string|null $attr = null): string
    {
        if (is_string($attr)) return " $attr";
        $out = '';
        foreach ($attr ?? [] as $k => $v) {
            $out .= " $k='" . htmlspecialchars((string) $v, ENT_QUOTES) . "'";
        }
        return $out;
    }
}

if (!function_exists('__requiredAttr')) {
    function __requiredAttr($isRequired = null, $isDisabled = null, $isReadonly = null): string
    {
        return ($isRequired ? ' required' : '') . ($isDisabled ? ' disabled' : '') . ($isReadonly ? ' readonly' : '');
    }
}

if (!function_exists('__selectedValue')) {
    /** "selected" when `$value` (or one of its items) equals `$constValue`. */
    function __selectedValue(mixed $value, int|string $constValue): string
    {
        foreach ((array) $value as $v) {
            if ($v == $constValue) return 'selected';
        }
        return '';
    }
}

if (!function_exists('__getImg')) {
    /** An `<img>` for `{images url}/{path}/{name}`; the base URL is `config('app.images_url')` (default `/images/`). */
    function __getImg(string $path, string $name, string $className = 'img-fluid'): string
    {
        $base = (string) config('app.images_url', route('/images/'));
        $src = rtrim($base, '/') . '/' . str_replace('.', '/', $path) . '/' . $name;
        return '<img class="' . htmlspecialchars($className, ENT_QUOTES) . '" src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    }
}

if (!function_exists('__invalidFeedback')) {
    /** Echo the validation message flashed for a field (see `input_errors`), once. */
    function __invalidFeedback(string $field): void
    {
        $errors = Session::peekFlash('input_errors', []);
        if (is_array($errors) && isset($errors[$field]) && $errors[$field] !== '') {
            echo '<small class="validation text-danger" style="margin-top: -15px;">' . htmlspecialchars((string) $errors[$field], ENT_QUOTES) . '</small>';
            unset($errors[$field]);
            Session::flash('input_errors', $errors);
        }
    }
}

if (!function_exists('__textAlign')) {
    /** Bootstrap alignment class for a table column index. */
    function __textAlign(int $int, bool $hasLineNo = false): string
    {
        if ($hasLineNo) {
            return match (true) {
                $int == 0 => 'text-center w-30',
                $int < 3 => 'text-start',
                default => 'text-end',
            };
        }
        return match (true) {
            $int == 0 => 'text-center',
            $int < 2 => 'text-start',
            default => 'text-end',
        };
    }
}
