<?php

declare(strict_types=1);

namespace Cast\Support;

/**
 * Finds debugging left in an app's own code: PHP calls like dd(), dump(), var_dump(), print_r() and JavaScript console.log() / debugger.
 * `console.error()` is allowed (it reports real failures and is fine in production). Used by `php cast deploy:scan` and `deploy:check`.
 *
 * A line can opt out on purpose: put  cast:keep  in a comment on the same line (or the line above):
 *     dump($order);            // cast:keep   (I need this in production)
 *
 * PHP is read with the tokenizer, so a function called dd in a string, a comment or a method named ->dump() is not a hit.
 */
final class DebugScanner
{
    /** PHP functions that print or stop: their output reaches visitors. print_r()/var_export() with true as the second argument return a string and are fine. */
    private const PHP_CALLS = ['dd', 'dump', 'vd', 'var_dump', 'print_r', 'var_export', 'debug_zval_refcount', 'debug_print_backtrace', 'phpinfo', 'xdebug_break', 'ray'];
    private const RETURNS_WHEN_TRUE = ['print_r', 'var_export'];
    private const JS = '/\bconsole\s*\.\s*(log|debug|info|trace|dir|dirxml|table|warn|group|groupCollapsed|groupEnd|time|timeEnd|timeLog|count|assert)\s*\(|^\s*debugger\s*;?/m';

    /** folders (relative to the app) that are never scanned */
    private const SKIP = ['vendor', 'storage', 'node_modules', '.git', 'public/assets/vendor', 'public/docs', 'public/build', 'tests'];

    /**
     * @param list<string> $roots absolute folders or files to scan
     * @return list<array{file: string, line: int, what: string}> file is relative to $base
     */
    public function scan(array $roots, string $base): array
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $hits = [];
        foreach ($roots as $root) {
            foreach ($this->files($root, $base) as $file) {
                $source = (string) file_get_contents($file);
                $relative = ltrim(substr(str_replace('\\', '/', $file), strlen($base)), '/');
                $found = str_ends_with($file, '.js') || str_ends_with($file, '.mjs') ? $this->js($source) : [...$this->php($source), ...$this->inlineScripts($source)];
                $lines = explode("\n", $source);
                foreach ($found as [$line, $what]) {
                    if (stripos($lines[$line - 1] ?? '', 'cast:keep') !== false || ($line > 1 && stripos($lines[$line - 2] ?? '', 'cast:keep') !== false)) continue;
                    $hits["$relative:$line:$what"] = ['file' => $relative, 'line' => $line, 'what' => $what];   // keyed: roots may overlap
                }
            }
        }
        $hits = array_values($hits);
        usort($hits, fn($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        return $hits;
    }

    /** @return list<array{0: int, 1: string}> */
    public function php(string $source): array
    {
        $tokens = @token_get_all($source);
        $hits = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), self::PHP_CALLS, true)) continue;

            $prev = $this->neighbour($tokens, $i, -1);
            $next = $this->neighbour($tokens, $i, 1);
            if ($next !== '(') continue;                                              // not a call
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;   // ->dump(), A::dump(), function dump()
            if (in_array(strtolower($t[1]), self::RETURNS_WHEN_TRUE, true) && $this->secondArgIsTrue($tokens, $i)) continue;
            $hits[] = [$t[2], strtolower($t[1]) . '()'];
        }
        return $hits;
    }

    /** @return list<array{0: int, 1: string}> */
    public function js(string $source): array
    {
        $hits = [];
        $clean = $this->withoutJsComments($source);
        if (preg_match_all(self::JS, $clean, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$text, $offset]) {
                $hits[] = [substr_count($clean, "\n", 0, $offset) + 1, str_contains($text, 'debugger') ? 'debugger' : 'console.' . $m[1][$i][0] . '()'];
            }
        }
        return $hits;
    }

    /** console.log() inside <script> blocks of a view */
    private function inlineScripts(string $source): array
    {
        $hits = [];
        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $source, $blocks, PREG_OFFSET_CAPTURE)) {
            foreach ($blocks[1] as [$code, $offset]) {
                $first = substr_count($source, "\n", 0, $offset) + 1;
                foreach ($this->js($code) as [$line, $what]) $hits[] = [$first + $line - 1, $what];
            }
        }
        return $hits;
    }

    /** Comments and strings become blank (newlines kept) so line numbers stay right and a console.log in a comment or a string is not a hit. */
    private function withoutJsComments(string $source): string
    {
        $out = (string) preg_replace_callback('~("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)|//[^\n]*|/\*.*?\*/~s', function ($m) {
            return preg_replace('/[^\n]/', ' ', $m[0]);     // strings and comments alike
        }, $source);
        return $out;
    }

    /** @param array<int, mixed> $tokens */
    private function neighbour(array $tokens, int $i, int $step): mixed
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return $tokens[$j];
        }
        return null;
    }

    /** print_r($x, true) */
    private function secondArgIsTrue(array $tokens, int $i): bool
    {
        $depth = 0;
        $commas = 0;
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            $t = $tokens[$j];
            $s = is_array($t) ? $t[1] : $t;
            if ($s === '(' || $s === '[') $depth++;
            elseif ($s === ')' || $s === ']') {
                $depth--;
                if ($depth === 0) return false;
            } elseif ($s === ',' && $depth === 1) {
                $commas++;
            } elseif ($commas === 1 && $depth === 1 && is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'true') {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function files(string $root, string $base): array
    {
        if (is_file($root)) return [$root];
        if (!is_dir($root)) return [];
        $skip = array_map(fn($s) => $base . '/' . $s, self::SKIP);
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $f) use ($skip): bool {
                $path = str_replace('\\', '/', $f->getPathname());
                foreach ($skip as $s) if ($path === $s || str_starts_with($path, $s . '/')) return false;
                return true;
            }
        ));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $name = $file->getFilename();
            if (str_ends_with($name, '.min.js') || $name === '_ide_helpers.php') continue;
            if (preg_match('/\.(php|js|mjs)$/i', $name)) $found[] = $path;
        }
        sort($found);
        return $found;
    }
}
