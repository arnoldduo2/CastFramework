<?php

declare(strict_types=1);

namespace Cast\Console\Commands;

use Cast\App\Application;
use Cast\Console\{Command, Input, Output};
use Cast\Core\Config;

/** `make:controller`, `make:model`, `make:middleware`, `make:command`, `make:rule`: creates a class from a stub. */
final class MakeCommand extends Command
{
    /** kind => [folder, suffix, stub] */
    private const KINDS = [
        'controller' => ['Controllers', 'Controller', <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace};

use Cast\Http\Controller;
use Cast\Http\Response;

class {class} extends Controller
{
    public function index(): Response
    {
        return $this->view('{view}', [
            'parentName' => '{view}',
            'pageName' => '{view}',
            'authguard' => 'private',
        ]);
    }
}
PHP],
        'model' => ['Models', '', <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace};

use Cast\Core\Model;

class {class} extends Model
{
    // The table is "{table}". Set `protected static ?string $table = 'name';` to change it.
}
PHP],
        'middleware' => ['Middleware', '', <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace};

use Cast\Contracts\Middleware;
use Cast\Http\Request;
use Cast\Http\Response;

class {class} implements Middleware
{
    public function handle(Request $request, mixed ...$args): ?Response
    {
        // return a Response to stop here, or null to continue
        return null;
    }
}
PHP],
        'command' => ['Console/Commands', 'Command', <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace};

use Cast\Console\{Command, Input, Output};

class {class} extends Command
{
    protected string $name = '{command}';
    protected string $description = '';

    public function handle(Input $input, Output $output): int
    {
        $output->info('Done.');
        return 0;
    }
}
PHP],
        'rule' => ['Rules', '', <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace};

use Cast\Contracts\Rule;

class {class} implements Rule
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return true;
    }

    public function message(): string
    {
        return ':field is invalid.';
    }
}
PHP],
    ];

    private string $kind;

    public function __construct(Application $app, string $kind = '')
    {
        parent::__construct($app);
        $this->kind = $kind;
        if ($kind !== '') {
            $this->name = "make:$kind";
            $this->description = "Create a new $kind class";
            $this->arguments = ['Name' => "The class name, e.g. Invoice" . (self::KINDS[$kind][1] !== '' ? ' (the suffix ' . self::KINDS[$kind][1] . ' is added when missing)' : '')];
            $this->options = ['--force' => 'Overwrite the file if it exists'];
            $this->examples = ["php cast make:$kind Invoice" => ''];
        }
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public function handle(Input $input, Output $output): int
    {
        $given = $input->argument(0);
        if ($given === null || !preg_match('/^[A-Za-z][A-Za-z0-9_\/\\\\]*$/', $given)) {
            $output->error("Usage: php cast {$this->name} <Name>");
            return 1;
        }

        [$folder, $suffix, $stub] = self::KINDS[$this->kind];
        $parts = preg_split('#[/\\\\]#', $given);
        $base = $this->studly(array_pop($parts));
        $class = $suffix !== '' && !str_ends_with($base, $suffix) ? $base . $suffix : $base;

        $root = (string) Config::get('app.namespace', 'App');
        $source = (string) Config::get('app.source_path', 'app');
        $sub = array_map([$this, 'studly'], $parts);
        $namespace = implode('\\', [$root, ...explode('/', $folder), ...$sub]);
        $path = $this->app->basePath($source . '/' . $folder . ($sub ? '/' . implode('/', $sub) : '') . "/$class.php");

        if (is_file($path) && !$input->hasOption('force')) {
            $output->error("$path already exists (use --force to overwrite).");
            return 1;
        }

        $view = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', preg_replace('/Controller$/', '', $class)));
        $code = strtr($stub, [
            '{namespace}' => $namespace,
            '{class}' => $class,
            '{view}' => $view,
            '{table}' => strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $class)),
            '{command}' => strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ':', preg_replace('/Command$/', '', $class))),
        ]);

        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, $code . "\n");
        $output->info("Created $namespace\\$class");
        $output->line("  $path");
        return 0;
    }

    private function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }
}
