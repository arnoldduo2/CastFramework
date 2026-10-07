<?php

declare(strict_types=1);

namespace Cast\Support;

/**
 * Reads what a component file says about itself: its docblocks and its `$prop ??= default` lines.
 * The VS Code extension has the same reader in JavaScript (editor/vscode/lib/docblock.js); both must produce exactly the JSON
 * in tests/fixtures/components/*.expected.json.
 *
 *   /**
 *    * Button component
 *    * @var string|null $label The text to display on the button
 *    * @var string|null $variant The variant (e.g., "primary", "secondary", "ghost")
 *    * @slot icon Optional icon HTML
 *    *​/
 *   $label ??= 'Button';
 *
 * @phpstan-type Prop array{name: string, type: ?string, description: string, required: bool, default: ?string, values: list<string>, kind: string, deprecated: bool|string, inferred: bool}
 * @phpstan-type Spec array{description: string, props: list<Prop>, slots: list<array{name: string, description: string}>, examples: list<string>, deprecated: bool|string}
 */
final class ComponentDocs
{
    private const PROP_TAGS = ['var', 'param', 'prop', 'property', 'property-read', 'property-write'];

    /** @return Spec */
    public static function parse(string $source): array
    {
        $source = (string) preg_replace('/\r\n?/', "\n", $source);

        $description = '';
        $props = [];
        $slots = [];
        $examples = [];
        $deprecated = false;

        foreach (self::docblocks($source) as $block) {
            if ($description === '' && $block['summary'] !== '') $description = $block['summary'];

            foreach ($block['tags'] as $tag) {
                if (in_array($tag['name'], self::PROP_TAGS, true)) {
                    $prop = self::propFromTag($tag, $source, $block['end']);
                    if ($prop !== null && !isset($props[$prop['name']])) $props[$prop['name']] = $prop;
                } elseif ($tag['name'] === 'slot') {
                    if (preg_match('/^(\S+)\s*([\s\S]*)$/', $tag['text'], $m) && !in_array($m[1], array_column($slots, 'name'), true)) {
                        $slots[] = ['name' => $m[1], 'description' => trim($m[2])];
                    }
                } elseif ($tag['name'] === 'example') {
                    if (trim($tag['raw']) !== '') $examples[] = trim($tag['raw']);
                } elseif ($tag['name'] === 'deprecated') {
                    $text = trim($tag['text']);
                    $deprecated = $text !== '' ? $text : true;
                }
            }
        }

        // defaults from the code: `$label ??= 'Button';`
        foreach (self::defaults($source) as [$name, $expression]) {
            if (!isset($props[$name])) {
                $props[$name] = self::newProp($name);
                $props[$name]['inferred'] = true;
            }
            $props[$name]['default'] = $expression;
            $props[$name]['required'] = false;
            if ($props[$name]['inferred']) $props[$name]['kind'] = self::kindOf(null, $expression);
        }

        $list = [];
        foreach ($props as $prop) $list[] = self::finish($prop);

        return ['description' => $description, 'props' => $list, 'slots' => $slots, 'examples' => $examples, 'deprecated' => $deprecated];
    }

    /** The spec of a component file, or null when it cannot be read. @return Spec|null */
    public static function parseFile(string $file): ?array
    {
        $source = @file_get_contents($file);
        return $source === false ? null : self::parse($source);
    }

    /**
     * Every component under the given folders: tag name (`Btns.Button`), file, spec.
     * @param list<string> $dirs folders with the component files (the first folder that has a tag wins)
     * @return list<array{tag: string, file: string, spec: Spec}>
     */
    public static function scan(array $dirs, string $ext = '.cast.php'): array
    {
        $found = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (!$file->isFile() || !str_ends_with($path, $ext)) continue;

                $relative = substr($path, strlen(str_replace('\\', '/', rtrim($dir, '/\\'))) + 1, -strlen($ext));
                $tag = implode('.', array_map([self::class, 'pascal'], explode('/', $relative)));
                if (isset($found[$tag])) continue;
                $found[$tag] = ['tag' => $tag, 'file' => $file->getPathname(), 'spec' => self::parse((string) file_get_contents($file->getPathname()))];
            }
        }
        ksort($found);
        return array_values($found);
    }

    /** `add-new` / `add_new` / `addNew` => `AddNew`. */
    public static function pascal(string $segment): string
    {
        return ucfirst((string) preg_replace_callback('/[-_]+([A-Za-z0-9])/', fn($m) => strtoupper($m[1]), $segment));
    }

    // ----------------------------------------------------------------- docblocks

    /** @return list<array{summary: string, tags: list<array{name: string, text: string, raw: string}>, end: int}> */
    private static function docblocks(string $source): array
    {
        $out = [];
        preg_match_all('/\/\*\*(.*?)\*\//s', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$whole, $offset]) {
            $summary = [];
            $tags = [];
            $current = null;
            foreach (explode("\n", $matches[1][$i][0]) as $line) {
                $line = rtrim((string) preg_replace('/^\s*\*? ?/', '', $line));
                if (preg_match('/^@([A-Za-z][A-Za-z0-9_-]*)\s?(.*)$/', $line, $t)) {
                    $tags[] = ['name' => strtolower($t[1]), 'text' => $t[2], 'raw' => $t[2]];
                    $current = count($tags) - 1;
                } elseif ($current !== null) {
                    $tags[$current]['raw'] .= "\n" . $line;
                    if (trim($line) !== '') $tags[$current]['text'] .= ($tags[$current]['text'] !== '' ? ' ' : '') . trim($line);
                } else {
                    $summary[] = $line;
                }
            }
            $out[] = [
                'summary' => (string) preg_replace('/\n{2,}/', "\n\n", trim(implode("\n", $summary))),
                'tags' => $tags,
                'end' => $offset + strlen($whole),
            ];
        }
        return $out;
    }

    /** @return Prop|null */
    private static function propFromTag(array $tag, string $source, int $blockEnd): ?array
    {
        $text = trim($tag['text']);
        $type = null;

        if (!str_starts_with($text, '$')) {
            [$type, $rest] = self::readType($text);
            $text = trim($rest);
        }
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)\s*([\s\S]*)$/', $text, $m)) {
            $name = $m[1];
            $text = $m[2];
        } elseif (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:\?\?=|=)/', substr($source, $blockEnd), $next)) {
            $name = $next[1];   // an inline `/** @var string The label */` belongs to the variable assigned right after it
        } else {
            return null;
        }

        $prop = self::newProp($name);
        $prop['type'] = $type;
        $prop['description'] = trim((string) preg_replace('/^[-–—]\s*/u', '', $text));
        $prop['required'] = true;   // until the type or a default says otherwise (see finish)
        $prop['documented'] = true;
        return $prop;
    }

    /** @return array{?string, string} the type at the start of the text (balanced <>, (), {}, quotes; unions and `):` return types may have spaces), and the rest */
    private static function readType(string $text): array
    {
        $n = strlen($text);
        $depth = 0;
        $quote = null;
        $i = 0;
        while ($i < $n) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($c === '\\') $i++;
                elseif ($c === $quote) $quote = null;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif (str_contains('<({[', $c)) {
                $depth++;
            } elseif (str_contains('>)}]', $c)) {
                $depth--;
            } elseif (ctype_space($c) && $depth <= 0) {
                $rest = ltrim(substr($text, $i));
                $before = rtrim(substr($text, 0, $i));
                if (preg_match('/^[|&]/', $rest) || preg_match('/[|&:]$/', $before)) {
                    $i++;
                    continue;
                }
                break;
            }
            $i++;
        }
        $type = trim(substr($text, 0, $i));
        return [$type !== '' ? $type : null, substr($text, $i)];
    }

    // -------------------------------------------------------------------- code

    /** @return list<array{string, string}> `$name ??= expression;` as [name, expression] */
    private static function defaults(string $source): array
    {
        $found = [];
        $offset = 0;
        while (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\?\?=\s*/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = self::expressionEnd($source, $start);
            $found[] = [$m[1][0], (string) preg_replace('/\s+/', ' ', trim(substr($source, $start, $end - $start)))];
            $offset = $end;
        }
        return $found;
    }

    private static function expressionEnd(string $src, int $i): int
    {
        $depth = 0;
        $quote = null;
        $n = strlen($src);
        for (; $i < $n; $i++) {
            $c = $src[$i];
            if ($quote !== null) {
                if ($c === '\\') $i++;
                elseif ($c === $quote) $quote = null;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif (str_contains('([{', $c)) {
                $depth++;
            } elseif (str_contains(')]}', $c)) {
                $depth--;
            } elseif ($c === ';' && $depth <= 0) {
                return $i;
            } elseif ($c === '?' && ($src[$i + 1] ?? '') === '>' && $depth <= 0) {
                return $i;
            }
        }
        return $i;
    }

    // ------------------------------------------------------------------ derived

    /** @return array{name: string, type: ?string, description: string, required: bool, default: ?string, values: list<string>, kind: string, deprecated: bool|string, inferred: bool} */
    private static function newProp(string $name): array
    {
        return ['name' => $name, 'type' => null, 'description' => '', 'required' => false, 'default' => null, 'values' => [], 'kind' => 'other', 'deprecated' => false, 'inferred' => false];
    }

    /** Fill in what follows from the type and the description: required, kind, allowed values, deprecation. */
    private static function finish(array $prop): array
    {
        $documented = $prop['documented'] ?? false;
        unset($prop['documented']);

        $types = self::splitUnion($prop['type']);
        $optionalType = (bool) array_filter($types, fn($t) => preg_match('/^(null|mixed)$/i', $t)) || str_starts_with((string) $prop['type'], '?');
        $prop['kind'] = self::kindOf($prop['type'], $prop['default']);
        // a flag that is left out is simply false, so a bool is never required
        $prop['required'] = $documented
            ? !($optionalType || $prop['default'] !== null || preg_match('/\[optional\]/i', $prop['description']) || $prop['kind'] === 'bool')
            : false;

        $literals = [];
        foreach ($types as $t) {
            if (($q = self::stripQuotes($t)) !== null) $literals[] = $q;
        }
        $nonNull = array_filter($types, fn($t) => !preg_match('/^null$/i', $t));
        if ($literals && count($literals) === count($nonNull)) {
            $prop['values'] = $literals;
        } elseif (in_array($prop['kind'], ['string', 'other'], true)) {
            $prop['values'] = self::valuesFromDescription($prop['description']);
        }
        // "Deprecated: use x", or an inline "@deprecated use x" anywhere in the description
        if (preg_match('/^\s*deprecated\b:?\s*([\s\S]*)$/i', $prop['description'], $dep) || preg_match('/(?:^|\s)@deprecated\b:?\s*([\s\S]*)$/i', $prop['description'], $dep)) {
            $text = trim($dep[1]);
            $prop['deprecated'] = $text !== '' ? $text : true;
        }

        // the same key order as the JavaScript reader
        return [
            'name' => $prop['name'], 'type' => $prop['type'], 'description' => $prop['description'], 'required' => $prop['required'],
            'default' => $prop['default'], 'values' => $prop['values'], 'kind' => $prop['kind'], 'deprecated' => $prop['deprecated'], 'inferred' => $prop['inferred'],
        ];
    }

    /** @return list<string> */
    private static function splitUnion(?string $type): array
    {
        if ($type === null || $type === '') return [];
        $t = trim($type);
        if (str_starts_with($t, '?')) $t = substr($t, 1) . '|null';

        $parts = [];
        $depth = 0;
        $quote = null;
        $start = 0;
        $n = strlen($t);
        for ($i = 0; $i < $n; $i++) {
            $c = $t[$i];
            if ($quote !== null) {
                if ($c === '\\') $i++;
                elseif ($c === $quote) $quote = null;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif (str_contains('<({[', $c)) {
                $depth++;
            } elseif (str_contains('>)}]', $c)) {
                $depth--;
            } elseif ($c === '|' && $depth === 0) {
                $parts[] = trim(substr($t, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($t, $start));
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    private static function stripQuotes(string $t): ?string
    {
        return preg_match('/^([\'"])(.*)\1$/s', $t, $m) ? $m[2] : null;
    }

    private static function kindOf(?string $type, ?string $default): string
    {
        $types = array_values(array_filter(self::splitUnion($type), fn($t) => !preg_match('/^null$/i', $t)));
        if ($types) {
            $all = fn(callable $test) => count(array_filter($types, $test)) === count($types);
            if ($all(fn($t) => preg_match('/^(bool|boolean|true|false)$/i', $t))) return 'bool';
            if ($all(fn($t) => preg_match('/^(int|integer|float|double|numeric|positive-int|negative-int|int<.*>)$/i', $t))) return 'number';
            if ($all(fn($t) => preg_match('/^(string|non-empty-string|class-string|numeric-string)$/i', $t) || self::stripQuotes($t) !== null)) return 'string';
            return 'other';
        }
        $d = trim((string) $default);
        if (preg_match('/^(true|false)$/i', $d)) return 'bool';
        if (preg_match('/^-?\d+(\.\d+)?$/', $d)) return 'number';
        if (preg_match('/^([\'"]).*\1$/s', $d)) return 'string';
        return 'other';
    }

    /** @return list<string> quoted or backticked words after "e.g.", "one of", "such as", "values:" or inside the first parentheses */
    private static function valuesFromDescription(string $description): array
    {
        if ($description === '') return [];
        $region = null;
        if (preg_match('/(?:\be\.g\.?,?|\bone of|\bsuch as|\bvalues?:|\boptions?:|\bpossible values?:)\s*([\s\S]*)$/i', $description, $m)) {
            $region = $m[1];
        } elseif (preg_match('/\(([^)]*)\)/', $description, $m)) {
            $region = $m[1];
        }
        if ($region === null) return [];

        $values = [];
        preg_match_all('/"([^"]+)"|\'([^\']+)\'|`([^`]+)`/', $region, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $v = ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
            if ($v !== '' && !in_array($v, $values, true)) $values[] = $v;
        }
        return count($values) <= 20 ? $values : [];
    }
}
