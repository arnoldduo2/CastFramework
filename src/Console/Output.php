<?php

declare(strict_types=1);

namespace Cast\Console;

/** Console output. Writes to a stream (STDOUT by default; tests pass a memory stream). */
final class Output
{
    /** @var resource */
    private $stream;
    private bool $colors;

    /** @param resource|null $stream */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->colors = $stream === null && function_exists('posix_isatty') && @posix_isatty(STDOUT);
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

    /** @param list<string> $headers @param list<list<string>> $rows */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
        }
        $format = fn(array $cells) => rtrim(implode('  ', array_map(
            fn($cell, $i) => str_pad((string) $cell, $widths[$i]),
            $cells,
            array_keys($cells)
        )));

        $this->line($format($headers));
        $this->line(implode('  ', array_map(fn($w) => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) $this->line($format($row));
    }

    private function paint(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m$text\033[0m" : $text;
    }
}
