<?php

declare(strict_types=1);

namespace Cast\Console;

/** Console output. Writes to a stream (STDOUT by default; tests pass a memory stream). */
final class Output
{
    /** @var resource */
    private $stream;
    private bool $colors;

    /** Colours used by the console (the framework's palette: green, blue, orange). 256-colour/true-colour escapes, plain text when colours are off. */
    private const STYLES = [
        'green' => '38;2;93;225;74',
        'blue' => '38;2;114;128;253',
        'orange' => '38;2;255;119;29',
        'red' => '31',
        'yellow' => '33',
        'grey' => '90',
        'bold' => '1',
        'title' => '1;38;2;93;225;74',
    ];

    /**
     * @param resource|null $stream  where to write (STDOUT by default)
     * @param bool|null $colors      force colours on or off; null = on for a terminal, off for pipes, files and when NO_COLOR is set (FORCE_COLOR forces them on)
     */
    public function __construct($stream = null, ?bool $colors = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->colors = $colors ?? ($stream === null && self::terminalSupportsColors());
    }

    private static function terminalSupportsColors(): bool
    {
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') return false;
        if (getenv('FORCE_COLOR') !== false && getenv('FORCE_COLOR') !== '' && getenv('FORCE_COLOR') !== '0') return true;
        if (!defined('STDOUT')) return false;
        if (DIRECTORY_SEPARATOR === '\\') return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT);
        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    public function colors(): bool
    {
        return $this->colors;
    }

    /** `$out->color('serve', 'green')`: the text in a style (green, blue, orange, red, yellow, grey, bold, title), or unchanged when colours are off. */
    public function color(string $text, string $style): string
    {
        return $this->paint($text, self::STYLES[$style] ?? $style);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->paint($text, '32'));
    }

    public function warn(string $text): void
    {
        $this->line($this->paint($text, '33'));
    }

    public function error(string $text): void
    {
        $this->line($this->paint($text, '31'));
    }

    /** @param list<string> $headers @param list<list<string>> $rows @param string|null $firstColumn a style for the first column (e.g. 'green') */
    public function table(array $headers, array $rows, ?string $firstColumn = null): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
        }
        // widths are measured on the plain text, colours are added after padding
        $format = fn(array $cells, ?string $style = null, ?string $first = null) => rtrim(implode('  ', array_map(
            function ($cell, $i) use ($widths, $style, $first) {
                $padded = str_pad((string) $cell, $widths[$i]);
                $use = $i === 0 && $first !== null ? $first : $style;
                return $use !== null ? $this->color($padded, $use) : $padded;
            },
            $cells,
            array_keys($cells)
        )));

        $this->line($format($headers, 'blue'));
        $this->line($this->color(implode('  ', array_map(fn($w) => str_repeat('-', $w), $widths)), 'grey'));
        foreach ($rows as $row) $this->line($format($row, null, $firstColumn));
    }

    private function paint(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m$text\033[0m" : $text;
    }
}
