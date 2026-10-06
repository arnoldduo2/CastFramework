<?php

declare(strict_types=1);

use Cast\Http\HttpException;
use Cast\Validation\LegacyRules;
use Cast\Validation\Validator;

if (!function_exists('validateParam')) {
    /** A value as `'int' | 'string' | 'array' | 'bool'`, or null when it is empty or the wrong type. */
    function validateParam(mixed $param, string $type = 'string'): mixed
    {
        if (empty($param)) return null;
        return match ($type) {
            'int' => is_numeric($param) ? (int) $param : null,
            'string' => is_string($param) ? trim($param) : null,
            'array' => is_array($param) ? $param : null,
            'bool' => is_bool($param) ? $param : null,
            default => null,
        };
    }
}

if (!function_exists('checkRouteParams')) {
    /** Redirect to `$route` unless `$params[$key]` is present (and, for `int`, a non-zero number). */
    function checkRouteParams(array $params, string $key, string $route = '/', string $type = 'int'): void
    {
        $value = $params[$key] ?? null;
        $ok = $type === 'str' ? (string) $value !== '' : (int) $value !== 0;
        if (!$ok) route_to($route);
    }
}

if (!function_exists('checkPostParams')) {
    /** Show the not-found page unless `$params[$key]` is present. */
    function checkPostParams(array $params, string $key, string $page): void
    {
        if (empty($params[$key])) throw new HttpException(404, "$page Number Is Required!");
    }
}

if (!function_exists('checkValidity')) {
    /** Answer `{"invalid":true}` when any of the fields is missing from the data. */
    function checkValidity(array $data, array $field): void
    {
        foreach ($field as $k) {
            if (!isset($data[$k])) {
                echo json_encode(['invalid' => true]);
                exit;
            }
        }
    }
}

if (!function_exists('validate')) {
    /** `validate($data, $rules)`: returns the validated data or throws a ValidationException. */
    function validate(array $data, array $rules, array $messages = [], array $labels = []): array
    {
        return Validator::make($data, $rules, $messages, $labels)->validate();
    }
}

if (!function_exists('formValidation')) {
    /** The old pipe-syntax validation (`"value|required|email|number|3"`). See {@see LegacyRules}. */
    function formValidation(array $data, bool $returnJson = false, ?array $feedback = null): int|string
    {
        return LegacyRules::run($data, $returnJson, $feedback);
    }
}
