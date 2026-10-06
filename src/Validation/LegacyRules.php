<?php

declare(strict_types=1);

namespace Cast\Validation;

use Cast\Core\Session;

/**
 * The old pipe syntax, kept for apps that already use it: each value is a string `"<value>|required|email|number|<minLength>"`.
 * Same messages, same result shape, same flash key (`input_errors`) as the original controller method.
 *
 *   LegacyRules::run(['email' => $email . '|required|email', 'qty' => $qty . '|required|number'])
 */
final class LegacyRules
{
    private const DEFAULT_MESSAGES = [
        'required' => 'Is Required!',
        'invalid_email' => 'Please Enter A Valid Email!',
        'invalid_number' => 'Enter Number Only!',
        'invalid_length' => 'Expected At Least {{num}} Characters!',
    ];

    /**
     * @param array<string, string> $data field => "value|required|email|number|minLength"
     * @param array<string, string>|null $feedback replacement messages (same keys as the defaults)
     * @return int|string The number of errors, or the errors as JSON when `$returnJson`.
     */
    public static function run(array $data, bool $returnJson = false, ?array $feedback = null): int|string
    {
        $msg = $feedback ?? self::DEFAULT_MESSAGES;
        $errors = [];

        foreach ($data as $key => $raw) {
            $field = explode('|', (string) $raw);
            if (isset($field[1]) && $field[1] === 'required' && $field[0] == '') {
                $errors[$key] = self::message($key, $msg['required']);
            }
            if (isset($field[2])) {
                if ($field[2] === 'email' && !filter_var($field[0], FILTER_VALIDATE_EMAIL)) {
                    $errors[$key] = self::message($key, $msg['invalid_email']);
                }
                if ($field[2] === 'number' && !filter_var($field[0], FILTER_VALIDATE_INT)) {
                    $errors[$key] = self::message($key, $msg['invalid_number']);
                }
            }
            if (isset($field[3]) && strlen($field[0]) < (int) $field[3]) {
                $errors[$key] = self::message($key, str_replace('{{num}}', $field[3], $msg['invalid_length']));
            }
        }

        Session::flash('input_errors', $errors);
        return $returnJson ? (string) json_encode($errors) : count($errors);
    }

    private static function message(string $key, string $text): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $key)) . ': ' . $text;
    }
}
