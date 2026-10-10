<?php

declare(strict_types=1);

use Cast\Console\Command;

test('help: every built-in command documents its arguments, options and an example, and every option it reads', function () {
    $app = console_app();
    $skip = ['MigrationCommand', 'MakeCommand', 'ModulesCommand'];
    $checked = 0;
    foreach (glob(dirname(__DIR__, 2) . '/src/Console/Commands/*Command.php') as $file) {
        $short = basename($file, '.php');
        if (in_array($short, $skip, true)) continue;
        $class = "Cast\\Console\\Commands\\$short";
        $command = new $class($app);
        $help = $command->help();
        ok($command->description() !== '', "$short has a description");
        ok(!str_contains($command->description(), '[--') && !str_contains($command->description(), '(--'), "$short: flags belong in the options, not the description");
        ok($help['examples'] !== [], "$short has an example");

        $documented = [];
        foreach (array_keys($help['options']) as $flag) $documented[] = preg_replace('/^--([a-z-]+).*/', '$1', $flag);
        preg_match_all("/(?:option|hasOption)\\('([a-z-]+)'/", (string) file_get_contents($file), $m);
        foreach (array_unique($m[1]) as $used) {
            ok(in_array($used, $documented, true), "$short reads --$used but does not document it");
        }
        $checked++;
    }
    ok($checked >= 25, 'checked the built-in commands');

    // the commands that share a base class read --seed / --force / --pretend there
    foreach (['migrate' => ['seed', 'pretend', 'step', 'force'], 'migrate:fresh' => ['seed', 'force'], 'migrate:rollback' => ['pretend', 'force', 'step']] as $name => $flags) {
        [$code, $out] = cast(['help', $name], $app);
        eq(0, $code, $out);
        foreach ($flags as $flag) has("--$flag", $out, "$name documents --$flag");
    }
});

test('help: php cast help <command>, <command> --help and -h print usage, arguments, options and examples', function () {
    $app = console_app();
    [$code, $out] = cast(['help', 'migrate:sync'], $app);
    eq(0, $code, $out);
    has('php cast migrate:sync [table] [options]', $out);
    has('Arguments:', $out);
    has('--init', $out);
    has('--collation=NAME', $out);
    has('Examples:', $out);
    has('php cast migrate:sync --init', $out);

    eq($out, cast(['migrate:sync', '--help'], $app)[1]);
    eq($out, cast(['migrate:sync', '-h'], $app)[1]);

    [$code, $out] = cast(['make:controller', '--help'], $app);
    eq(0, $code);
    has('php cast make:controller <Name> [options]', $out);
    has('Controller is added', $out);

    [$code, $out] = cast(['help', 'nope'], $app);
    eq(1, $code);
    has('not defined', $out);
    [$code, $out] = cast(['help', 'migrate:s'], $app);
    has('migrate:status', $out);

    // a --help request never runs the command
    [$code, $out] = cast(['down', '--help'], $app);
    eq(0, $code);
    ok(!is_file($app->basePath('storage/framework/maintenance.json')), 'down --help does not put the app down');

    has('php cast help <command>', cast(['list'], $app)[1]);
    has('migrate:sync', cast(['help'], $app)[1]);
});

test('help --markdown: the whole command reference, to the screen or a file', function () {
    $app = console_app();
    [$code, $out] = cast(['help', '--markdown'], $app);
    eq(0, $code, $out);
    has('## `migrate:sync`', $out);
    has('| `--init` |', $out);
    has('## `serve`', $out);
    [$code, $out] = cast(['help', '--markdown', '--write=docs/commands.md'], $app);
    eq(0, $code, $out);
    has('## `db:sequence`', (string) file_get_contents($app->basePath('docs/commands.md')));
});

test('help: a custom command without documentation still lists and runs', function () {
    $app = console_app();
    $cmd = new class($app) extends Command {
        protected string $name = 'hello';
        protected string $description = 'Say hello';
        public function handle(\Cast\Console\Input $i, \Cast\Console\Output $o): int { $o->line('hi'); return 0; }
    };
    $stream = fopen('php://memory', 'w+');
    $kernel = new \Cast\Console\Kernel($app, new \Cast\Console\Output($stream));
    $kernel->add($cmd);
    $kernel->run(['help', 'hello']);
    rewind($stream);
    $out = (string) stream_get_contents($stream);
    has('php cast hello', $out);
    has('Say hello', $out);
});
