<?php

declare(strict_types=1);

namespace Cast\Services;

/**
 * Password strength rules. Also available as the `password` validation rule (`password:len=10,uc=2`).
 *
 *   PasswordPolicy::check('secret1', ['len' => 8, 'uc' => 1])   // '' when OK, otherwise the problem as a sentence
 */
final class PasswordPolicy
{
    public const DEFAULTS = ['len' => 8, 'lc' => 1, 'uc' => 1, 'nums' => 1, 'sp' => 0];

    /**
     * @param array{len?: int, lc?: int, uc?: int, nums?: int, sp?: int} $options minimum length, lowercase, uppercase, numbers, special characters
     * @param bool $enforce false skips the check (for apps where strength is a setting)
     * @return string An empty string when the password passes.
     */
    public static function check(string $password, array $options = [], bool $enforce = true): string
    {
        if (!$enforce) return '';

        $need = $options + self::DEFAULTS;
        $have = [
            'lc' => preg_match_all('/[a-z]/', $password),
            'uc' => preg_match_all('/[A-Z]/', $password),
            'nums' => preg_match_all('/\d/', $password),
            'sp' => preg_match_all('/\W/', $password),
        ];
        $labels = [
            'lc' => '{n} lowercase letter',
            'uc' => '{n} uppercase letter',
            'nums' => '{n} number',
            'sp' => '{n} special character',
        ];

        $problems = [];
        if (mb_strlen($password) < $need['len']) $problems[] = $need['len'] . ' minimum characters';
        foreach ($have as $kind => $count) {
            if ($count < $need[$kind]) $problems[] = str_replace('{n}', (string) $need[$kind], $labels[$kind]);
        }

        return $problems ? 'Password must have at least ' . implode(', ', $problems) : '';
    }
}
