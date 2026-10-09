<?php

declare(strict_types=1);

use Cast\Support\ComponentDocs;

$componentFixtures = dirname(__DIR__) . '/fixtures/components';

test('ComponentDocs: every shared fixture gives exactly the JSON the JavaScript reader gives', function () use ($componentFixtures) {
    $files = glob($componentFixtures . '/*.cast.php');
    ok(count($files) >= 8, 'the fixtures are there');
    foreach ($files as $file) {
        $expected = json_decode((string) file_get_contents(str_replace('.cast.php', '.expected.json', $file)), true);
        $actual = ComponentDocs::parse((string) file_get_contents($file));
        eq($expected, json_decode(json_encode($actual), true), basename($file));
    }
});

test('ComponentDocs: the Button example from the docs', function () use ($componentFixtures) {
    $spec = ComponentDocs::parseFile($componentFixtures . '/button.cast.php');
    eq('Button component', $spec['description']);
    eq(['label', 'type', 'href', 'variant'], array_column($spec['props'], 'name'));
    $variant = $spec['props'][3];
    eq('string|null', $variant['type']);
    eq('The button variant (e.g., "primary", "secondary", "ghost")', $variant['description']);
    eq(['primary', 'secondary', 'ghost'], $variant['values']);
    eq("'primary'", $variant['default']);
    ok(!$variant['required']);
    eq('string', $variant['kind']);
});

test('ComponentDocs: required means no null in the type and no default', function () use ($componentFixtures) {
    $props = array_column(ComponentDocs::parseFile($componentFixtures . '/required.cast.php')['props'], null, 'name');
    ok($props['title']['required'] && $props['qty']['required']);
    ok(!$props['note']['required'], 'has a default');
    ok(!$props['hint']['required'], 'string|null');
});

test('ComponentDocs: names found only in the code are inferred and untyped; a file with nothing is empty', function () use ($componentFixtures) {
    $spec = ComponentDocs::parseFile($componentFixtures . '/inferred.cast.php');
    eq(['title', 'hasLabel', 'limit', 'items'], array_column($spec['props'], 'name'));
    ok(array_reduce($spec['props'], fn($c, $p) => $c && $p['inferred'] && $p['type'] === null, true));
    eq(['string', 'bool', 'number', 'other'], array_column($spec['props'], 'kind'));
    eq("['a', 'b; c']", $spec['props'][3]['default'], 'a ; inside a string does not end the expression');
    eq(['description' => '', 'props' => [], 'slots' => [], 'examples' => [], 'deprecated' => false], ComponentDocs::parseFile($componentFixtures . '/empty.cast.php'));
    eq(null, ComponentDocs::parseFile($componentFixtures . '/missing.cast.php'));
});

test('ComponentDocs: slots, examples and deprecation', function () use ($componentFixtures) {
    $spec = ComponentDocs::parseFile($componentFixtures . '/slots.cast.php');
    eq([['name' => 'left', 'description' => 'The HTML on the left side, usually badges'], ['name' => 'footer', 'description' => 'Optional footer']], $spec['slots']);
    has('<Slot name="left">', $spec['examples'][0]);
    eq('Use ListRow instead', $spec['deprecated']);
});

test('ComponentDocs: scan lists components by tag name, in sub-folders, with kebab/snake/Pascal file names', function () {
    $dir = app_dir([
        'components/card.cast.php' => "<?php\n/** A card.\n * @var string \$title The title */\n",
        'components/btns/add-new.cast.php' => "<?php \$label ??= 'Add';\n",
        'components/forms/text_input.cast.php' => '<input>',
        'components/forms/TextArea.cast.php' => '<textarea>',
        'components/readme.md' => 'not a component',
        'more/card.cast.php' => 'second folder, same tag: ignored',
        'more/chip.cast.php' => '<i>',
    ]);
    $found = ComponentDocs::scan(["$dir/components", "$dir/more"]);
    eq(['Btns.AddNew', 'Card', 'Chip', 'Forms.TextArea', 'Forms.TextInput'], array_column($found, 'tag'));
    $card = array_column($found, null, 'tag')['Card'];
    eq('A card.', $card['spec']['description']);
    ok(str_ends_with(str_replace('\\', '/', $card['file']), 'components/card.cast.php'), 'the first folder wins');
    eq('Add', trim(array_column($found, null, 'tag')['Btns.AddNew']['spec']['props'][0]['default'], "'"));
    eq([], ComponentDocs::scan(["$dir/nope"]));
});

test('ComponentDocs: odd input does not throw', function () {
    foreach (['', '/**', '/** @var */', '/** @var string */', '/** @var $x */', "/** @slot */", '$a ??= ', "<?php /** @var string|\$x */ \$x ??= [", "/** \n * @var 'unterminated \$a */"] as $source) {
        $spec = ComponentDocs::parse($source);
        ok(is_array($spec['props']), json_encode($source));
    }
    $big = ComponentDocs::parse(str_repeat("/** @var string \$a The a */\n\$a ??= 'x';\n", 3000));
    eq(1, count($big['props']), 'the same prop documented again is kept once');
});

test('components command: table, one component, --json, --markdown, --write', function () {
    $dir = init_dir();
    [$code, $out] = cast_in($dir, 'init --demo --no-migrate');
    eq(0, $code, $out);

    [$code, $out] = cast_in($dir, 'components');
    eq(0, $code, $out);
    has('<Btns.Button>', $out);
    has('label, type, href, variant', $out);

    [$code, $out] = cast_in($dir, 'components Btns.Button --json');
    eq(0, $code, $out);
    $json = json_decode($out, true);
    eq(1, count($json['components']));
    eq('Btns.Button', $json['components'][0]['tag']);
    eq('Button component', $json['components'][0]['description']);
    eq(['primary', 'secondary', 'ghost'], $json['components'][0]['props'][3]['values']);

    [$code, $out] = cast_in($dir, 'components Nope');
    eq(1, $code);
    has('No component', $out);

    [$code, $out] = cast_in($dir, 'components --markdown');
    has('## `<Btns.Button>`', $out);
    has('| `variant` |', $out);

    [$code, $out] = cast_in($dir, 'components --write=docs/components.md');
    eq(0, $code, $out);
    has('## `<Card>`', (string) file_get_contents("$dir/docs/components.md"));
});

test('components --check: passes when docs match the code and fails when they do not', function () {
    $dir = init_dir();
    cast_in($dir, 'init --demo --no-migrate');
    [$code, $out] = cast_in($dir, 'components --check');
    eq(0, $code, $out);

    $bad = "$dir/src/resources/views/components/bad.cast.php";
    file_put_contents($bad, "<?php\n/**\n * Bad one\n * @var string \$ghost never used\n */\n\$size ??= 'm';\n?>\n<p><?= \$size ?></p>\n");
    [$code, $out] = cast_in($dir, 'components --check');
    eq(1, $code, $out);
    has('"ghost" is documented but the file never uses it', $out);
    has('"size" has a default', $out);
});

test('make:component: the generated file is documented and round-trips through the reader', function () {
    $dir = init_dir();
    cast_in($dir, 'init');
    [$code, $out] = cast_in($dir, "make:component Btns.AddNew --props=\"label:string=Add,variant:'primary'|'ghost'='primary',href:?string,block:bool\"");
    eq(0, $code, $out);
    $file = "$dir/src/resources/views/components/btns/add-new.cast.php";
    ok(is_file($file), 'file created at the engine\'s kebab path');

    $spec = \Cast\Support\ComponentDocs::parseFile($file);
    eq(['label', 'variant', 'href', 'block'], array_column($spec['props'], 'name'));
    eq(false, $spec['props'][0]['required']);
    eq("'Add'", $spec['props'][0]['default']);
    eq(['primary', 'ghost'], $spec['props'][1]['values']);
    eq(false, $spec['props'][2]['required']);
    eq('bool', $spec['props'][3]['type']);
    ok(!in_array(true, array_column($spec['props'], 'inferred'), true), 'every prop is documented');

    [$code, $out] = cast_in($dir, 'make:component Btns.AddNew');
    eq(1, $code);
    has('already exists', $out);
    [$code, $out] = cast_in($dir, 'make:component btns');
    eq(1, $code);
    [$code, $out] = cast_in($dir, 'components --check');
    eq(0, $code, $out);
});

test('init: writes AGENTS.md once and never overwrites it', function () {
    $dir = init_dir();
    cast_in($dir, 'init');
    $text = (string) file_get_contents("$dir/AGENTS.md");
    has('php cast components --json', $text);
    file_put_contents("$dir/AGENTS.md", "mine\n");
    cast_in($dir, 'init --force');
    eq("mine\n", file_get_contents("$dir/AGENTS.md"));
});
