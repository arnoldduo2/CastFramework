<?php

declare(strict_types=1);

namespace Cast\Validation;

use Cast\Core\QueryBuilder;
use Cast\Services\PasswordPolicy;

/**
 * The built-in rules. Each takes the value, the rule's parameters (`min:3` => ['3']), all the data and the field name.
 * Empty values are skipped by every rule except the `required*` ones (so optional fields stay optional).
 */
final class Rules
{
    /** Default messages; `:field` is the field label, `:0`, `:1` the rule parameters. */
    public const MESSAGES = [
        'required' => ':field is required.',
        'required_if' => ':field is required.',
        'string' => ':field must be text.',
        'int' => ':field must be a whole number.',
        'numeric' => ':field must be a number.',
        'float' => ':field must be a decimal number.',
        'bool' => ':field must be true or false.',
        'array' => ':field must be a list.',
        'json' => ':field must be valid JSON.',
        'email' => ':field must be a valid email address.',
        'url' => ':field must be a valid URL.',
        'ip' => ':field must be a valid IP address.',
        'min' => ':field must be at least :0.',
        'max' => ':field must be at most :0.',
        'between' => ':field must be between :0 and :1.',
        'length' => ':field must be exactly :0 characters.',
        'in' => ':field must be one of: :list.',
        'not_in' => ':field has a value that is not allowed.',
        'regex' => ':field has an invalid format.',
        'alpha' => ':field may only contain letters.',
        'alpha_num' => ':field may only contain letters and numbers.',
        'date' => ':field must be a valid date.',
        'before' => ':field must be before :0.',
        'after' => ':field must be after :0.',
        'same' => ':field must match :0.',
        'confirmed' => ':field confirmation does not match.',
        'unique' => ':field is already in use.',
        'exists' => ':field does not exist.',
        'file' => ':field must be an uploaded file.',
        'max_kb' => ':field must not be larger than :0 KB.',
        'mimes' => ':field must be one of these file types: :list.',
        'password' => ':field is not strong enough.',
    ];

    /** Rules that are checked even when the value is empty. */
    public const RUN_WHEN_EMPTY = ['required', 'required_if'];

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param list<string> $p */
    public static function check(string $rule, mixed $value, array $p, array $data, string $field): bool
    {
        return match ($rule) {
            'required' => !self::isEmpty($value) && !(is_string($value) && trim($value) === ''),
            'required_if' => self::requiredIf($value, $p, $data),
            'string' => is_string($value),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric', 'number' => is_numeric($value),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false,
            'bool', 'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($value),
            'json' => is_string($value) && json_decode($value) !== null && json_last_error() === JSON_ERROR_NONE,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'min' => self::size($value) >= (float) ($p[0] ?? 0),
            'max' => self::size($value) <= (float) ($p[0] ?? 0),
            'between' => self::size($value) >= (float) ($p[0] ?? 0) && self::size($value) <= (float) ($p[1] ?? 0),
            'length' => mb_strlen((string) $value) === (int) ($p[0] ?? 0),
            'in' => in_array((string) $value, $p, true),
            'not_in' => !in_array((string) $value, $p, true),
            'regex' => is_scalar($value) && @preg_match(implode(',', $p), (string) $value) === 1,
            'alpha' => is_string($value) && preg_match('/^\pL+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pN]+$/u', $value) === 1,
            'date' => self::date($value, $p[0] ?? null) !== null,
            'before' => ($a = self::date($value, null)) !== null && ($b = strtotime($p[0] ?? '')) !== false && $a < $b,
            'after' => ($a = self::date($value, null)) !== null && ($b = strtotime($p[0] ?? '')) !== false && $a > $b,
            'same' => ($data[$p[0] ?? ''] ?? null) === $value,
            'confirmed' => ($data[$field . '_confirmation'] ?? null) === $value,
            'unique' => !self::row($p, $value, ignore: true),
            'exists' => self::row($p, $value),
            'file' => is_array($value) && isset($value['tmp_name']) && ($value['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK,
            'max_kb' => is_array($value) && (($value['size'] ?? 0) / 1024) <= (float) ($p[0] ?? 0),
            'mimes' => is_array($value) && in_array(strtolower(pathinfo((string) ($value['name'] ?? ''), PATHINFO_EXTENSION)), array_map('strtolower', $p), true),
            'password' => PasswordPolicy::check((string) $value, self::passwordOptions($p)) === '',
            default => throw new \InvalidArgumentException("Unknown validation rule \"$rule\"."),
        };
    }

    /** Numbers compare by value, strings by length, arrays by count. */
    private static function size(mixed $value): float
    {
        return match (true) {
            is_array($value) => count($value),
            is_numeric($value) => (float) $value,
            default => mb_strlen((string) $value),
        };
    }

    private static function requiredIf(mixed $value, array $p, array $data): bool
    {
        $other = $data[$p[0] ?? ''] ?? null;
        $needs = in_array((string) $other, array_slice($p, 1), true);
        return !$needs || !self::isEmpty($value);
    }

    private static function date(mixed $value, ?string $format): ?int
    {
        if (!is_string($value) || $value === '') return null;
        if ($format !== null) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            return $d && $d->format($format) === $value ? $d->getTimestamp() : null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
    }

    /** `unique:table,column[,ignoreId[,idColumn]]` / `exists:table,column` */
    private static function row(array $p, mixed $value, bool $ignore = false): bool
    {
        [$table, $column] = [$p[0] ?? '', $p[1] ?? ''];
        $query = QueryBuilder::table($table)->where($column, $value);
        if ($ignore && isset($p[2]) && $p[2] !== '') $query->and($p[3] ?? 'id', '!=', $p[2]);
        return $query->exists();
    }

    /** @param list<string> $p `password:len=10,uc=1` */
    private static function passwordOptions(array $p): array
    {
        $options = [];
        foreach ($p as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $options[trim($k)] = (int) $v;
            }
        }
        return $options;
    }
}
