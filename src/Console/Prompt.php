<?php

declare(strict_types=1);

namespace Cast\Console;

/**
 * Questions for the console. When the input is not interactive (a script, CI, `--no-interaction`) every question returns its default,
 * so a command can ask questions and still run unattended.
 */
final class Prompt
{
    /** @var resource */
    private $in;

    /** @param resource|null $in stdin by default */
    public function __construct(private Output $out, private bool $interactive, $in = null)
    {
        $this->in = $in ?? STDIN;
    }

    /** True when stdin is a terminal and the command was not told to stay quiet. */
    public static function canAsk(Input $input): bool
    {
        if ($input->hasOption('no-interaction') || $input->hasOption('n') || $input->hasOption('yes')) return false;
        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    public function interactive(): bool
    {
        return $this->interactive;
    }

    public function ask(string $question, string $default = ''): string
    {
        if (!$this->interactive) return $default;
        $this->out->line($question . ($default !== '' ? " [$default]" : '') . ': ');
        $answer = fgets($this->in);
        return $answer === false || trim($answer) === '' ? $default : trim($answer);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        if (!$this->interactive) return $default;
        for ($i = 0; $i < 3; $i++) {
            $answer = strtolower($this->ask($question . ' (y/n)', $default ? 'y' : 'n'));
            if (in_array($answer, ['y', 'yes'], true)) return true;
            if (in_array($answer, ['n', 'no'], true)) return false;
            $this->out->warn('Please answer y or n.');
        }
        return $default;
    }

    /**
     * @param array<string, string> $choices key => what it means (the keys are what the user types, or the number)
     * @return string the chosen key
     */
    public function choice(string $question, array $choices, string $default): string
    {
        if (!$this->interactive) return $default;
        $keys = array_keys($choices);
        $this->out->line($question);
        foreach ($keys as $i => $key) $this->out->line('  ' . ($i + 1) . ') ' . str_pad((string) $key, 10) . $choices[$key] . ($key === $default ? '   (default)' : ''));
        for ($tries = 0; $tries < 3; $tries++) {
            $answer = $this->ask('Choose', (string) (array_search($default, $keys, true) + 1));
            if (ctype_digit($answer) && isset($keys[(int) $answer - 1])) return (string) $keys[(int) $answer - 1];
            if (isset($choices[$answer])) return $answer;
            $this->out->warn('Type a number from the list.');
        }
        return $default;
    }
}
