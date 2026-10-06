<?php

declare(strict_types=1);

namespace Cast\Validation;

use Cast\Contracts\Rule;
use Closure;

/**
 * Validates an array of data against rules.
 *
 *   $v = Validator::make($request->all(), [
 *       'email' => 'required|email|unique:users,email',
 *       'age'   => ['required', 'int', 'between:18,99'],
 *       'code'  => ['required', new MyRule()],            // a Cast\Contracts\Rule
 *       'tag'   => ['regex:/^a|b$/'],                      // use an array when a rule contains "|"
 *   ]);
 *   if ($v->fails()) { $v->errors(); }  // or ->validate() to throw ValidationException, returning the clean data
 *
 * Fields that are empty and not `required` skip their other rules. `sometimes` skips a field that isn't in the data.
 * Custom rules by name: `Validator::extend('even', fn($value, $params, $data, $field) => $value % 2 === 0, ':field must be even.')`.
 */
final class Validator
{
    /** @var array<string, array{Closure, string}> */
    private static array $extensions = [];
    /** @var array<string, list<string>> */
    private array $errors = [];
    private bool $ran = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string|Rule|Closure>> $rules
     * @param array<string, string> $messages `field.rule` => message, or `rule` => message
     * @param array<string, string> $labels   field => label used in messages
     */
    public function __construct(
        private array $data,
        private array $rules,
        private array $messages = [],
        private array $labels = [],
    ) {}

    public static function make(array $data, array $rules, array $messages = [], array $labels = []): self
    {
        return new self($data, $rules, $messages, $labels);
    }

    /** Register a named rule. The callable gets ($value, array $params, array $data, string $field). */
    public static function extend(string $name, callable $check, string $message = ':field is invalid.'): void
    {
        self::$extensions[$name] = [Closure::fromCallable($check), $message];
    }

    public static function forgetExtensions(): void
    {
        self::$extensions = [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function passes(): bool
    {
        $this->run();
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    /** @return array<string, string> First message per field. */
    public function firstErrors(): array
    {
        return array_map(fn(array $m) => $m[0], $this->errors());
    }

    /** The data for the fields that have rules (call after `passes()`, or use `validate()`). */
    public function validated(): array
    {
        return array_intersect_key($this->data, $this->rules);
    }

    /** @throws ValidationException */
    public function validate(): array
    {
        if ($this->fails()) throw new ValidationException($this->errors);
        return $this->validated();
    }

    private function run(): void
    {
        if ($this->ran) return;
        $this->ran = true;

        foreach ($this->rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $list = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            if (in_array('sometimes', $list, true) && !array_key_exists($field, $this->data)) continue;

            foreach ($list as $rule) {
                if ($rule === 'sometimes' || $rule === 'nullable') continue;

                [$name, $params] = $this->parse($rule);
                if (Rules::isEmpty($value) && !in_array($name, Rules::RUN_WHEN_EMPTY, true)) continue;

                if (!$this->passesRule($rule, $name, $params, $value, $field)) {
                    $this->errors[$field][] = $this->message($rule, $name, $params, $field);
                    if ($name === 'required') break;
                }
            }
        }
    }

    /** @return array{string, list<string>} */
    private function parse(mixed $rule): array
    {
        if (!is_string($rule)) return [$rule instanceof Rule ? 'custom' : 'closure', []];
        $pos = strpos($rule, ':');
        if ($pos === false) return [$rule, []];
        return [substr($rule, 0, $pos), explode(',', substr($rule, $pos + 1))];
    }

    private function passesRule(mixed $rule, string $name, array $params, mixed $value, string $field): bool
    {
        if ($rule instanceof Rule) return $rule->passes($field, $value, $this->data);
        if ($rule instanceof Closure) return (bool) $rule($value, $this->data, $field);
        if (isset(self::$extensions[$name])) return (bool) (self::$extensions[$name][0])($value, $params, $this->data, $field);

        return Rules::check($name, $value, $params, $this->data, $field);
    }

    private function message(mixed $rule, string $name, array $params, string $field): string
    {
        $template = $this->messages["$field.$name"] ?? $this->messages[$name] ?? match (true) {
            $rule instanceof Rule => $rule->message(),
            $rule instanceof Closure => ':field is invalid.',
            isset(self::$extensions[$name]) => self::$extensions[$name][1],
            default => Rules::MESSAGES[$name === 'integer' ? 'int' : ($name === 'number' ? 'numeric' : ($name === 'boolean' ? 'bool' : $name))] ?? ':field is invalid.',
        };

        $replace = [':field' => $this->label($field), ':list' => implode(', ', $params)];
        foreach ($params as $i => $param) $replace[":$i"] = $param;
        return strtr($template, $replace);
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field));
    }
}
