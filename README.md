# CastFramework

A small PHP MVC framework for apps that render pages on the server and enhance them with plain JavaScript and jQuery.
It is built on [CastTemplateEngine](https://github.com/arnoldduo2/CastTemplateEngine) (component tags in `.cast.php` views).

- Routing for **GET, POST, PUT, PATCH and DELETE**, with route groups, middleware and permission checks.
- A `Request` / `Response` pair, one **global CSRF token** checked centrally for every state-changing verb.
- A validation system (string rules, rule objects, closures) plus the old pipe syntax for legacy code.
- Sessions with flash data, an `Auth` service, a query builder that binds every value, a small `Model`.
- Views with auto-loaded page CSS and JS, error pages, maintenance mode, an update hook, a console (`php cast`).
- An optional **SPA layer**: opted-in pages load lazily and swap only their content (a small JS client, no build step); `View` decides full page, partial or modal.
- A first-class **JSON API** (`routes/api.php`): always-JSON errors, bearer tokens with abilities, rate limiting, CORS, for a front-end framework or other servers.
- No ERP or business logic: it holds only what every app needs. See [docs/ERP-PORTING.md](docs/ERP-PORTING.md) for what was left out.

Requires PHP 8.1 or newer and `ext-pdo`, `ext-mbstring`, `ext-json`.

> **Status:** version 0.x: the API may still change between minor versions (see the [changelog](CHANGELOG.md)).

## Contents

New here? Read **[docs/GETTING-STARTED.md](docs/GETTING-STARTED.md)** first: the workflow of a page, where every file goes, and how to wire a feature. The full index of guides is [docs/README.md](docs/README.md).

| | |
| --- | --- |
| **Start** | [Install](#install) · [A first app](#a-first-app) · [Configuration](#configuration) |
| **Build pages** | [Routing](#routing) · [Controllers](#controllers) · [Views](#views) · [Helpers](#helpers) · [Static files](#static-files) |
| **Requests and safety** | [Request and Response](#request-and-response) · [CSRF](#csrf) · [Validation](#validation) · [Auth and services](#auth-and-services) · [Security notes](#security-notes) |
| **Data** | [Models and the query builder](#models-and-the-query-builder) · [Migrations](#migrations) · [Legacy databases](#legacy-databases-migratesync) · [Another ORM](#using-another-orm) |
| **Front end and API** | [SPA](#spa-pages-without-full-reloads) · [JSON API](#json-api) |
| **Tooling** | [Console](#console) ([all commands](docs/COMMANDS.md)) · [Editor support](#editor-support) · [Testing](#testing) |
| **Running it** | [Windows / XAMPP](#running-on-windows--xampp-or-in-a-sub-folder) · [Modules](#modules-optional-gating) · [Deploying](#deploying-to-production) · [Errors, maintenance and updates](#errors-maintenance-and-updates) · [Contracts](#contracts) · [Upgrading](#upgrading) · [Versioning](#versioning) |

## Install

```bash
mkdir my-app && cd my-app
composer init --name=me/my-app --no-interaction
composer require anode/cast-framework     # answer "y" when Composer asks to trust the plugin (see below)
php cast init                       # asks a few questions, then creates the app here (add --demo for the full starter app)
php cast serve                      # http://127.0.0.1:8000
```

`cast init` writes `public/`, `bootstrap/`, `config/`, `routes/`, a home page, `.env` (named after the folder), `storage/` and the `.gitignore` lines, and adds the `App\` namespace to
your `composer.json` (the app works right away: the framework maps `App\` to `app/` itself; `composer dump-autoload -o` is for production). It never overwrites a file you already have unless you pass `--force` (and never `.env`).

`php cast init --demo` copies the [`starter/`](starter) app instead: it keeps the welcome page and adds **Log in / Register** (top right), CRUD on an items table with `PUT`/`DELETE` and an edit popup, a stats page, and a JSON API with bearer tokens, on SQLite (log in as `admin@example.com` / `password`, or register). Both start on a welcome page with the menu *Docs* and *Demo the Cast Framework*; every file the commands write explains itself in comments. When you have seen enough, `php cast demo:strip` removes the demo and leaves a starter pack (shell, crud, auth or auth-crud). While you build, a small floating dock (bottom right) links to the demo and the docs; it only appears in development and `CAST_DOCK=false` turns it off.

**The `cast` launcher.** The package includes a small Composer plugin that puts the console launcher, a file named `cast`, in your project root after every install or update (it never overwrites
an existing one). That is what makes `php cast <command>` work from the first command. Composer asks you once to trust it; non-interactively, allow it first:
`composer config allow-plugins.anode/cast-framework true`. If you decline, `php vendor/bin/cast init` creates the same file.

## A first app

```
my-app/
  .env
  bootstrap/app.php         returns the Application (used by public/index.php and `cast`)
  public/index.php          the only file the web server runs
  config/                   app.php, auth.php, ...  (only list what you change)
  routes/web.php            routes (every *.php in this folder is loaded)
  routes/api.php            optional: the JSON API, registered under /api
  src/                      Controllers/, Models/, Services/, Providers/, helpers/   (`php cast init` asks for the folder; config `app.source_path`)
  resources/views/          layouts, pages, components/
  resources/css, resources/js    auto-loaded per page
  storage/                  logs, compiled views, maintenance state (must be writable)
```

`public/index.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
```

`bootstrap/app.php`:

```php
<?php
return new Cast\App\Application(dirname(__DIR__));
// An app with a different layout (like the ERP) can move folders:
// new Application($base, ['paths' => ['config' => 'src/config', 'routes' => 'src/Routes', 'views' => 'src/resources/views', 'resources' => 'src/resources']]);
```

`routes/web.php`:

```php
use Cast\Core\Router;

Router::get('/', fn() => 'Hello');
Router::get('/hello/{name}', [App\Controllers\HelloController::class, 'show']);
```

`app/Controllers/HelloController.php` (create it with `php cast make:controller Hello`):

```php
namespace App\Controllers;

use Cast\Http\Controller;
use Cast\Http\Response;

class HelloController extends Controller
{
    public function show(string $name): Response
    {
        return $this->view('hello.hello', ['parentName' => 'hello', 'pageName' => 'hello', 'authguard' => 'public', 'name' => $name]);
    }
}
```

## Configuration

### `.env`

Read once per request. Supports `KEY=value`, `export KEY=value`, `"quoted"` and `'literal'` values, `# comments`, and the casts
`true`, `false`, `null`, `empty`. Keys are case-insensitive. Real environment variables are used when the file has no entry.

```php
env('APP_NAME', 'default');      // any value
Env::bool('APP_DEBUG');          // 'false', '0', 'off', 'no', '' are false
Env::int('PORT', 8000);
Env::set('FEATURE', 'on');       // updates memory and rewrites only that line of the file
```

| Key | Default | Meaning |
| --- | --- | --- |
| `APP_NAME` | `Cast App` | Name used in pages and logs |
| `APP_KEY` | none | Secret for `sign()` and `encrypt()`. `php cast key:generate` (`php cast init` makes one) |
| `APP_ENV` | `production` | `development` recompiles views when files change |
| `APP_DEBUG` | `false` | Debug output and fresh `?v=` asset versions |
| `APP_VERSION` | none | Asset cache-busting version (production) |
| `APP_BASE_PATH` | empty | URL folder when not at the web root, e.g. `/my-app` |
| `APP_TIMEZONE` | `UTC` | Used by `getDateTime()` |
| `APP_VIEWS_EXT` | `.cast.php` | View file extension |
| `DB_CONN`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | mysql, 127.0.0.1 | Database (`DB_CONN` may be `mysql`, `pgsql`, `sqlite`; a trailing `:` is accepted) |
| `SESSION_NAME` | `cast_session` | Name of the session cookie |
| `COOKIE_LIFE` | `0` | Seconds the cookie lives; 0 = until the browser closes |
| `COOKIE_PATH`, `COOKIE_DOMAIN` | `/`, empty | Where the cookie is sent |
| `COOKIE_SECURE` | `false` | `true` when the site is served over https (cookie only sent over https) |
| `COOKIE_HTTP_ONLY` | `true` | Scripts cannot read the cookie |
| `COOKIE_SITE` | `Lax` | `Lax`, `Strict` or `None` (`None` needs `COOKIE_SECURE=true`) |
| `CORS_ALLOWED_ORIGINS` | none | Comma separated exact origins |

`php cast init` writes a `.env` with every setting above (and its own `APP_KEY`) and a `.env.example` with the same settings and no key, safe to commit.

### `config/*.php`

`php cast make:config <name>` writes `config/<name>.php` with every setting of that section and its default, documented and ready to edit: `app`, `database`, `session`, `cors`, `static`, `view`, `api`, `spa`, `auth`, `models`, `helpers`, `request`, `console`, `error-handler` (`--all` for every one, `--list` to see them). You only need a file for what you change.

Each file returns an array and becomes a top-level key (`config/app.php` => `config('app.name')`). Your files are **merged over the framework
defaults**: associative arrays merge key by key, lists and scalars replace. List only what you change.

```php
config('app.name');                  // dot notation
config(['app.debug' => true]);       // set
Cast\Core\Config::has('auth.login_path');
```

| Key | Default | Meaning |
| --- | --- | --- |
| `app.providers` | `[]` | Your service providers (the core ones always load) |
| `app.middleware` | `[Maintenance::class]` | Global middleware, run before routing |
| `app.base_path` | `''` | Stripped from the request path, added by `route()` |
| `app.auto_update` | `false` | Run a bound `updater` automatically |
| `app.error_handler` | `true` | Register `anode/error-handler` on web requests: `false` for off, or an options array; the same options can live in `config/error-handler.php` (`php cast make:config error-handler`) |
| `app.error_pages` | `framework` | `custom`: you build `<views>/errors/{404,403,500...}.cast.php`; an empty one falls back to the framework's page (with a note in development) |
| `app.key` | `APP_KEY` | The app key |
| `app.namespace`, `app.source_path` | `App`, `app` | Where `make:*` commands create classes (`php cast init` sets `src`) |
| `request.sanitizer` | `null` | Callable that sanitises `getPost()` data |
| `auth.session_key`, `login_path`, `home_path`, `permissions_key` | `login`, `/login`, `/`, `permissions` | Auth defaults |
| `view.ext`, `view.components` | `.cast.php`, `components` | Views |
| `static` | css, js, styles, fonts, images, public | [Static file map](#static-files) |
| `cors.allowed_origins` | `[]` | Exact origins allowed |
| `spa.enabled`, `initial`, `root`, `view` | `true`, `lazy`, `body`, `#cast-view` | [SPA](#spa-pages-without-full-reloads) |
| `api.prefix`, `api.middleware`, `api.tokens.table` | `/api`, `[]`, `api_tokens` | [JSON API](#json-api) |
| `models.namespace` | `App\Models` | Where `getModelInstance()` looks |
| `helpers.custom` | `null` | Folder of your own helper files |
| `database.*` | from `.env` | `driver`, `host`, `port`, `name`, `user`, `pass`, `charset`, `dsn`, `connection` (a callable returning your own PDO), `migrations.table` / `.path`, `seeder` |

### Providers

A provider binds services (`register`) and runs setup (`boot`, after every provider registered). List yours in `config/app.php`:

```php
class AppServiceProvider extends Cast\App\ServiceProvider
{
    public function register(): void { $this->app->singleton('auth', fn() => new Cast\Services\Auth(new UserStore())); }
    public function boot(): void     { $this->app->make('view')->share('appName', config('app.name')); }
}
```

Container: `$app->bind($id, fn)` (new each time), `singleton`, `set($id, $value)`, `make($id)`, `has($id)`; `app('view')` is `app()->make('view')`.
Core bindings: `view`, `guard`, `maintenance`, and `error_handler` (web only).

## Routing

```php
use Cast\Core\Router;
use Cast\Http\Middleware\Authenticate;

Router::middleware([Authenticate::class, 'private'], function () {          // everything inside needs a login
    Router::group('/items', function () {
        Router::get('/', ItemsController::class);                          // class alone calls index()
        Router::get('/{id}', [ItemsController::class, 'show']);
        Router::post('/', [ItemsController::class, 'store']);
        Router::put('/{id}', [ItemsController::class, 'update']);
        Router::patch('/{id}', [ItemsController::class, 'patch']);
        Router::delete('/{id}', [ItemsController::class, 'destroy'])->middleware(['delete-items']);   // permission slugs
    });
});

Router::match(['GET', 'POST'], '/search', $handler);
Router::any('/ping', fn() => 'pong');
```

- **Handlers:** a closure, `[Class::class, 'method']`, `'Class::method'`, or `Class::class` (calls `index`). A handler receives the route parameters
  (`{id}`), then the merged request input array as its last argument. Controllers are created per request with `new`.
- **Return values:** a `Response` is sent; an array becomes JSON; a string becomes HTML; a handler that echoes (and returns nothing) is captured
  (and recognised as JSON when it echoes JSON).
- **Matching:** a static path beats a parameterised one registered earlier. `HEAD` uses the `GET` route.
- **404 and 405:** an unknown path is a 404; a known path with another verb is a **405 with an `Allow` header** (JSON for ajax/JSON clients, a page otherwise).
  `Router::_404($handler)` customises unmatched `GET` requests.
- **HTML forms and verbs:** a `POST` with `_method=PUT|PATCH|DELETE` (or the `X-HTTP-Method-Override` header) is routed as that verb.
- **Route options:** `->middleware(['slug', ...])` permission slugs, any one is enough, checked with the bound `Guard`; `->use([Class::class, ...$args])` adds a
  middleware to one route; `->withoutCsrf()` skips CSRF for one route; `->name('x')`. `Router::exemptCsrf('/exact/path')` exempts a path.
- **Middleware:** a class implementing `Cast\Contracts\Middleware`. The spec `[Class::class, ...$args]` calls `handle(Request $request, ...$args)`. Return `null`
  to continue, a `Response` to stop, or throw `HttpException`. Group middleware runs in the order registered, then the CSRF check, then permission slugs, then the handler.

`php cast route:list` prints the table of routes with handlers and middleware.

## Request and Response

`Cast\Http\Request` is created once per request. In a controller it is `$this->request`; anywhere else `request()` or `Request::current()`.

```php
$request->method();               // 'PUT' (honours _method on POST)
$request->path();                 // '/items/5' without app.base_path
$request->query('page', 1);       // ?page=
$request->input('name');          // body first, then query string
$request->all(); only('a', 'b'); except('password'); has('a'); filled('a');
$request->body();                 // parsed JSON or form body: works for PUT, PATCH and DELETE too
$request->header('X-Foo'); ip(); isAjax(); isJson(); expectsJson();
$request->file('avatar');         // entry of $_FILES
$request->route('id');            // route parameter
```

Input is returned **raw**. `getPost()` (the helper) returns the JSON body (or the form body with `getPost(true)`) passed through the sanitiser in
`config('request.sanitizer')` (default: trim). Set it to your own callable to apply an app-wide policy.

`Cast\Http\Response`:

```php
return Response::success('Saved!', ['id' => 5]);     // {"status":"success","msg":"Saved!","data":{"id":5}}
return Response::error('Not allowed', 403);          // {"status":"error","msg":"Not allowed"}
return Response::error('Invalid', 422, ['email' => 'Required']);   // + "data":{"errors":{...}}
return Response::html($html, 200);
return Response::redirect('/login');                 // 302; second argument changes the status
return Response::noContent();
return (new Response($body))->status(201)->header('X-Foo', 'bar');
```

AJAX endpoints always answer `{status, msg, data}`.

`abort(404, 'Not here')` (or `throw new HttpException(404)`) from anywhere stops with an error page, or JSON for ajax clients.

## CSRF

One token per session (`Session::csrfToken()`), renewed on login/logout (`Session::regenerate()`).

- **Forms:** `<?= __csrf() ?>` renders `<input type="hidden" name="_token" ...>`.
- **Ajax:** send the header `X-CSRF-TOKEN` (or `X-XSRF-TOKEN`), or `_token` in the JSON body. Put the token in a `<meta name="csrf-token">`.
- **Checked centrally** for every **POST, PUT, PATCH and DELETE** route, with `hash_equals` (API requests with a bearer token, or without a session cookie, are the one exception: see [CSRF and cookies on the API](#csrf-and-cookies-on-the-api)). A missing or wrong token is a **419**
  (JSON `{status:'error'}` for ajax, a page otherwise). GET requests never need one.
- `__verifyCsrf($token)` checks a token by hand (throws 419). `Csrf::valid($request)` returns a bool.

## Controllers

Extend `Cast\Http\Controller`. It holds only what every controller needs:

```php
class ItemsController extends Controller
{
    public function store(): Response
    {
        $data = $this->validate(['name' => 'required|min:2', 'qty' => 'required|int']);   // throws ValidationException
        $id = Items::query()->insert($data);
        return $this->request->expectsJson() ? $this->success('Added', ['id' => $id], 201) : $this->redirect('/items');
    }
}
```

| Member | Purpose |
| --- | --- |
| `$this->request` | The current `Request` (works even if your constructor does not call `parent::__construct()`) |
| `view($view, $data, $status)` | Render a view as a `Response` |
| `success()`, `error()`, `redirect()` | Response shortcuts (`redirect` goes through `route()`) |
| `validate($rules, $messages, $labels)` | Validate the request input, return the clean data |
| `authorize($slug \| array)` | 403 unless the `Guard` grants it |
| `getModelInstance($name)` | Find a model class by name (`config('models.namespace')`) |

Business logic belongs in services, not in the controller.

## Validation

```php
use Cast\Validation\Validator;

$v = Validator::make($request->all(), [
    'email' => 'required|email|unique:users,email',
    'age'   => ['required', 'int', 'between:18,99'],
    'code'  => ['required', new MyRule(), fn($value, $data, $field) => $value !== 'x'],
    're'    => ['regex:/^a|b$/'],               // use the array form when a rule contains a "|"
]);
$v->fails(); $v->errors();                      // field => list of messages
$v->firstErrors();                              // field => first message
$data = $v->validate();                         // clean data, or throws ValidationException
```

Fields that are empty and not `required` skip their other rules. `sometimes` skips a field that is not in the data. `required` stops further rules for that field.

| Rule | Meaning |
| --- | --- |
| `required`, `required_if:field,value` | Present and not blank |
| `string`, `int` / `integer`, `numeric` / `number`, `float`, `bool` / `boolean`, `array`, `json` | Types |
| `email`, `url`, `ip` | Formats |
| `min:n`, `max:n`, `between:a,b`, `length:n` | Numbers by value, strings by length, arrays by count (`length` is characters) |
| `in:a,b`, `not_in:a,b`, `regex:/.../`, `alpha`, `alpha_num` | Values and patterns |
| `date`, `date:d/m/Y`, `before:date`, `after:date` | Dates |
| `same:field`, `confirmed` (`field_confirmation`) | Matching fields |
| `unique:table,column[,ignoreId[,idColumn]]`, `exists:table,column` | Database checks (bound queries) |
| `file`, `max_kb:n`, `mimes:png,jpg` | Uploaded files |
| `password`, `password:len=10,uc=2` | Strength (see `PasswordPolicy`) |

**Messages:** defaults like "Email is required."; `:field` is the label (`first_name` => "First Name"), `:0`/`:1` are the rule parameters.
Override per rule (`['required' => '...']`), per field and rule (`['email.required' => '...']`), and set labels with the fourth argument.

**Custom rules:** a class implementing `Cast\Contracts\Rule` (`passes()`, `message()`), a closure, or `Validator::extend('even', fn($value, $params, $data, $field) => ..., ':field must be even.')`.
`php cast make:rule Uppercase` creates one.

**What happens on failure:** `validate()` throws `ValidationException`. The Kernel answers **422 JSON** `{status:'error', msg, data:{errors:{field:'message'}}}` for ajax/JSON
requests, and for browser forms it flashes `input_errors` and `old` (without passwords or the token) and redirects back to the referrer.
In views: `<?php __invalidFeedback('email'); ?>` prints the flashed message once; `Session::peekFlash('old')` has the previous input.

**Legacy syntax:** `formValidation(['email' => $email . '|required|email', 'qty' => $qty . '|required|number|3'])` (value, then `required`, `email` or `number`, then a minimum length)
keeps the old messages and the `input_errors` key.

## Auth and services

Services are plain classes; the controller calls them.

```php
$auth = new Cast\Services\Auth(new UserStore());              // UserStore implements Cast\Contracts\UserProvider
if ($auth->attempt($email, $password)) { /* logged in: new session id, new CSRF token, no password hash kept */ }
$user = $auth->verify($email, $password);                      // check credentials only, no session (API token login)
$auth->user(); $auth->check(); $auth->logout();
Auth::hash($password);                                         // Hash::make(): Argon2id, or bcrypt where PHP has no Argon2; $2a$ hashes from older apps verify too
```

**Passwords and secrets.** Passwords are *hashed* (one way): `Cast\Support\Hash::make()` / `check()` / `needsRehash()` (and the helpers `hashPassword()` / `verifyPassword()`) use **Argon2id** when your PHP has it and **bcrypt** otherwise; the algorithm is read from the stored hash, so old bcrypt hashes keep working. When a user logs in with a weaker hash than `config/hashing.php` asks for (`HASH_DRIVER`, bcrypt `cost`, Argon2 `memory`/`time`), `Auth` stores a new one if your user provider has a `rehash(array $user, string $newHash)` method. Values you must read back (a token, a card number) are *encrypted* with the **app key** instead: `encrypt()` / `decrypt()` (AES-256-GCM) and `sign()` / `unsign()` (HMAC-SHA256) from `Cast\Support\Crypt`, keyed by `APP_KEY` (`php cast key:generate`). Never encrypt a password; never hash something you need back.

**Your own services.** `php cast make:service Mailer` writes `<source>/Services/MailerService.php` with a how-to in its comment. For things older apps need there are working examples: `--example=printer` (ESC/POS receipt printing over the network or USB), `--example=barcode` (Code 128 as SVG, no library), `--example=qrcode` (QR codes through `chillerlan/php-qrcode`). Bind one in your provider (`$this->app->singleton('printer', fn() => new ReceiptService('192.168.1.50:9100'))`) and call `app('printer')` anywhere.

`UserProvider` has three methods: `findByCredentials($identifier): ?array`, `onLogin(array $user)`, `passwordKey(): string`.

**Guard:** `Cast\Contracts\Guard` (`check($guard)`, `can($permission)`, `user()`) decides who may see what. The default `SessionGuard` reads the
user array from the session (`auth.session_key`) and its `permissions` list. Bind your own as `guard` when permissions live elsewhere:

```php
$this->app->singleton('guard', fn() => new MyGuard());
```

`Authenticate` middleware: `[Authenticate::class, 'private']` (logged in, otherwise redirect to `auth.login_path`, or 401 for ajax),
`'auth'` (guests only; signed-in users go to `auth.home_path`), `'public'` (anyone).

**PasswordPolicy::check($password, ['len' => 8, 'lc' => 1, 'uc' => 1, 'nums' => 1, 'sp' => 0], $enforce = true)** returns `''` or a sentence ("Password must have at least ...").

**ModelPersister** saves and patches through models that implement `Savable` / `Patchable` (and `HasLineItems` for header + lines documents), then runs an optional callback:

```php
$id = (new ModelPersister())->save(Invoices::class, $data, fn($data, $id) => audit('saved', $id));   // throws DuplicateEntryException when the model reports a duplicate
```

## Models and the query builder

```php
class JournalEntries extends Cast\Core\Model {}          // table: journal_entries (override: protected static ?string $table = 'x';)

JournalEntries::getAll(1);                              // where active = 1, order by id
JournalEntries::getOne($id); getOne('a@b.co', 'email');
JournalEntries::exists('email', $email, $ignoreId);
JournalEntries::updateColumns(['name' => 'x'], $id); setActive(0, $id); deleteRows($id);
JournalEntries::paginate(page: 2, perPage: 15, orderBy: 'id');   // ['data', 'total', 'page', 'per_page', 'last_page']
JournalEntries::raw('SELECT ... WHERE a = :a', ['a' => 1]);     // bound parameters
```

`Model::query()` / `QueryBuilder::table('t')`: `select`, `where`, `and`, `or`, `whereIn`, `orderBy` (repeatable), `limit(n, offset)`, `get`, `first`, `fetch`,
`count`, `exists`, `insert` (returns the id), `update`, `delete`. **Every value is bound** and every identifier (table, column, operator, direction) is validated;
anything else throws `InvalidArgumentException`. Transactions: `Model::beginTrans() / commitTrans() / rollbackTrans() / inTransaction()`.
There are no joins, grouping or sums: use `raw()` for those.

## Migrations

Describe your tables in PHP; the same migration runs on MySQL, PostgreSQL and SQLite.

```bash
php cast make:migration create_orders_table          # database/migrations/2026_10_07_101500_create_orders_table.php
php cast make:migration add_status_to_orders_table   # the stub is picked from the name (create_x_table, add_y_to_x_table)
php cast migrate                                     # run what is pending (one batch)
php cast migrate:status                              # what ran, in which batch
php cast migrate:rollback                            # undo the last batch  (--step=2: the last two migrations)
php cast migrate:refresh --seed                      # undo everything, run everything again, seed
php cast migrate:fresh --seed                        # drop ALL tables, run everything, seed
php cast migrate --pretend                           # print the SQL, change nothing
```

```php
// database/migrations/2026_10_07_101500_create_orders_table.php
use Cast\Database\{Blueprint, Migration, Schema};

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('orders', function (Blueprint $table) {
            $table->id();                                              // auto-increment primary key
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // references users.id
            $table->string('number', 30)->unique();
            $table->decimal('total', 12, 2)->default(0);
            $table->enum('status', ['new', 'paid', 'void'])->default('new');
            $table->text('note')->nullable();
            $table->timestamps();                                      // created_at, updated_at
            $table->index(['user_id', 'status']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('orders');
    }
};
```

| Area | Methods |
| --- | --- |
| Tables | `create`, `table` (change), `drop`, `dropIfExists`, `rename`, `hasTable`, `hasColumn`, `columns`, `tables`, `statement($sql, $bindings)` (anything else), `pretend(fn)` |
| Columns | `id`, `increments`, `string($n, $len)`, `char`, `text`, `mediumText`, `longText`, `integer`, `bigInteger`, `smallInteger`, `tinyInteger`, `boolean`, `decimal($n, $p, $s)`, `float`, `double`, `date`, `time`, `dateTime`, `timestamp`, `timestamps`, `softDeletes`, `json`, `uuid`, `binary`, `enum($n, [...])`, `foreignId` |
| Modifiers | `nullable`, `default($v)` (a value or `Schema::raw('CURRENT_TIMESTAMP')`), `useCurrent`, `unsigned`, `unique`, `index`, `primary`, `after($col)` (MySQL), `comment($t)`, `constrained($table = null)` |
| Keys | `index`, `unique`, `primary`, `foreign($col)->references('id')->on('t')->onDelete('cascade')` / `cascadeOnDelete` / `nullOnDelete`, `dropIndex`, `dropUnique`, `dropPrimary`, `dropForeign`, `dropColumn`, `renameColumn` |

- Columns are `NOT NULL` unless you say `nullable()`. Every name is checked and quoted for the database, every default is escaped: nothing from a migration is put in SQL unchecked.
- PostgreSQL and SQLite run each migration in a transaction, so a failed one leaves nothing behind. **MySQL commits every `CREATE`/`ALTER` itself**, so a migration that fails half way
  stays half applied: keep MySQL migrations small. A lock file (`storage/framework/migrate.lock`) stops two deploys from migrating at the same time.
- SQLite cannot add a foreign key or a primary key to an existing table, nor add a `NOT NULL` column without a default: the migration says so and points you to `$schema->statement()`.
- **Production:** every command that changes the database refuses to run when `APP_ENV=production` unless you add `--force` (`migrate:status`, `make:migration` and `--pretend` are always allowed).
- The migrations table is `migrations` (`config('database.migrations.table')`); the folder is `database/migrations` (`paths.database`, or `database.migrations.path`).

**Seeders:** `php cast make:seeder UserSeeder` creates `database/seeders/UserSeeder.php` (`namespace Database\Seeders; class UserSeeder extends Cast\Database\Seeder { public function run(): void {...} }`).
`php cast db:seed` runs `DatabaseSeeder` (change it with `config('database.seeder')`), `--class=UserSeeder` runs one, `migrate --seed` migrates and seeds. A seeder calls others with `$this->call(OtherSeeder::class)`.

### Legacy databases: `migrate:sync`

A database that already has tables can join the migration system without retyping them. `migrate:sync` reads the tables (MySQL/MariaDB, PostgreSQL, SQLite) and writes one create-table migration per table, with columns, defaults, indexes, **foreign keys** and collations,
ordered so that referenced tables come first. The files are recorded as run, because the tables exist: `php cast migrate` leaves them alone, and a fresh database built from the files gets the same structure.

```
php cast migrate:sync --init                 FIRST: test every relationship; PASS / WARN / BROKEN; writes nothing; exit 1 when something is broken
php cast migrate:sync                        every table that has no migration yet
php cast migrate:sync users                  one table (also  --table=users,orders  and  --except=logs,cache)
php cast migrate:sync --pretend              print the files instead of writing them
php cast migrate:sync --collation=utf8mb4_unicode_ci    write every table with this collation (MySQL)
php cast migrate:sync --auto-increment       also write each table's next auto-increment value
php cast migrate:sync --no-record            write the files but leave them pending (then  migrate:baseline  records them)
```

- **`--init`** checks each declared foreign key (the target exists, the columns exist, the types can be joined, the columns are indexed, and **every row has its parent**, with sample values for the orphans) and also the `customer_id`-style columns that have no constraint, reporting where they would point and whether the data agrees.
  Rows that point to nothing are `BROKEN`; a missing constraint, a type mismatch or a missing index is a `WARN`.
- Types the migration builder has no equivalent for (`GEOMETRY`, `YEAR`, `MEDIUMINT`, `JSONB`...) are written as the closest match and listed as `NOTE:` comments in the file and on screen: read the files before you commit them.
- A table that already has a migration (`->create('table'`) is skipped. Foreign keys to a table that is not synced yet are flagged. Cycles between tables get a separate `add_foreign_keys_to_...` migration (SQLite accepts forward references, so it keeps them in place).
- **Collations:** the builder has `$table->charset('latin1')`, `$table->collation('utf8mb4_bin')` (MySQL, table level) and `->collation('...')` on a column (MySQL, PostgreSQL, SQLite). `migrate:sync` reads them and only writes what differs from the default.
- **Auto-increment:** `$schema->autoIncrement('orders', 5000)` sets the next value, `$schema->nextAutoIncrement('orders')` reads it. `php cast db:sequence` lists every counter with the highest id, flags the ones that are **behind** the data (the next insert would collide: this happens on PostgreSQL after imports with explicit ids), `--sync` moves them to `MAX(id)+1`, and `db:sequence orders --set=5000` sets one.
- It needs the built-in migrator; with another ORM use its own "generate from database".

**API tokens table:** `php cast token:schema --migration` writes the migration for it.

### Using another ORM

Migrations, the connection and the models each sit behind a small contract, so Doctrine, Eloquent, Cycle or Phinx can replace the built-in pieces and `php cast migrate` keeps working:
bind your adapter as `migrator`, return the ORM's `PDO` from `config('database.connection')`, and implement `ModelContract` on its models. See [docs/ORM-ADAPTERS.md](docs/ORM-ADAPTERS.md).

## Views

Views are `.cast.php` files under `resources/views`. They are PHP with the CastTemplateEngine tags added: `<Card title="x">...</Card>`, `value={$expr}`, `<Slot name="left">`.
See [its README](https://github.com/arnoldduo2/CastTemplateEngine#readme) for the syntax. Compiled copies are cached in `storage/framework/views`
(recompiled on change when `APP_ENV=development` or `APP_DEBUG=true`).

Pages follow a shell + partial layout, with CSS and JS found by name:

```
resources/views/items/items.cast.php            the page shell: header, content partial, footer
resources/views/items/partials/items.cast.php   the content
resources/css/items/items.css                    loaded automatically for parentName=items, pageName=items
resources/js/items/items.module.js
```

The controller passes `parentName`, `pageName` and `authguard`; the layout asks for the files:

```php
<?= __modules('app', 'css') ?>                       // resources/css/app.css
<?= __modules("$parentName.$pageName", 'css') ?>     // resources/css/items/items.css  (only if the file exists)
<?= __modules("$parentName.$pageName", 'js') ?>      // resources/js/items/items.module.js
```

```php
__includes('layouts.header', $data);                 // echo a layout or partial
Component('btns.add-new', ['link' => 'fleet']);      // components/btns/add-new.php (legacy) or .cast.php
$this->view('items.items', $data);                   // in a controller: a Response
views('items.items', $data);                         // legacy helper: echoes the page and returns true
app('view')->share('appName', 'My App');             // data every view receives
```

Inside a view the data is available as variables and as `$data`. Components: a `.cast.php` component receives camelCased props and `$children`;
a legacy `.php` component receives `$data`. A view that is not found in your folder falls back to the framework's own (error pages).

**The `@` shorthand (template engine 1.1.0 and newer).** Instead of `<?php foreach (...): ?>` and `<?= $x ?>` you can write `@foreach ($users as $user):` ... `@endforeach`, `@{ $user->name }`, `@forelse` / `@empty` / `@endforelse`, `@for`, `@while`, `@if` / `@elseif` / `@else` / `@endif`, `@unless`, `@isset`, `@switch`, `@break`, `@continue` and `@php` ... `@endphp`. `@{ }` prints as it is, like `<?= ?>` (use `htchars()` for user text). Plain PHP keeps working in the same file. Full table: the [template engine README](https://github.com/arnoldduo2/CastTemplateEngine#the--shorthand-for-php). Update with `composer update anode/cast-template-engine`.

## Running on Windows / XAMPP, or in a sub-folder

Quick try (no Apache needed): `php cast serve` serves `public/` on http://127.0.0.1:8000.

Under XAMPP (`C:\xampp\htdocs\my-app`):

1. Use PHP 8.1+ and enable `extension=pdo_sqlite`, `extension=mbstring` (and `pdo_mysql` for MySQL) in `php.ini`; restart Apache. Install [Composer](https://getcomposer.org).
2. In `C:\xampp\htdocs`: `mkdir my-app`, `cd my-app`, `composer init --name=me/my-app --no-interaction`, `composer require anode/cast-framework` (answer `y` to the plugin question), `php cast init --demo`.
3. Either point a virtual host's `DocumentRoot` at `my-app/public` (then nothing else to configure), or browse to `http://localhost/my-app/public/` and set `APP_BASE_PATH=/my-app/public` in `.env`.
   `public/.htaccess` sends every request that is not a real file to `index.php`, so `mod_rewrite` must be on and `AllowOverride All` set for the folder. `storage/` must be writable.
4. The app's URLs, assets and the SPA client all use `APP_BASE_PATH`; use `route('/items')` in views instead of hand-written paths.

## SPA: pages without full reloads

Opt a page in with `'spa' => true`. The first visit loads the layout and a placeholder; a small JavaScript client then fetches the content, and
later links swap only what changed. Pages that do not opt in load normally, and `views()` / `$this->view()` keep working as they are:
**`View` is the one place that decides full page, partial or modal**; controllers do not change.

```php
return $this->view('items.items', ['parentName' => 'items', 'pageName' => 'items', 'authguard' => 'private', 'spa' => true, 'items' => $items]);
```

Layout (`resources/views/layouts/header.cast.php`): add the client once in `<head>`, with the page's `authguard`:

```php
<meta name="csrf-token" content="<?= htchars(\Cast\Core\Session::csrfToken()) ?>">
<?= __cast($data['authguard'] ?? '') ?>          <!-- /cast/cast.css and /cast/cast.module.js, served by the framework -->
<?= __modules('app', 'css') ?>
<?= __modules("$parentName.$pageName", 'css') ?>
```

The page file stays the shell (header, content partial, footer). The partial named `{parentName}.partials.{pageName}` is the *content area*:
it is what gets fetched and swapped, so keep everything that changes from page to page in it.

### What the server does

| Request | Response |
| --- | --- |
| Browser, page without `spa` | The full page, as before |
| Browser, `spa` page | The full layout; the content area is `<div id="cast-view" data-cast-page="items.items" data-cast-lazy>` with a skeleton (`spa.initial` = `lazy`, default), or the real content (`inline`, no extra request) |
| Cast request (`X-Cast-Request: 1`) for a `spa` page | JSON envelope with the content (below) |
| Cast request for a page that is not `spa` | `{type: 'reload'}`: the client does a normal page load |
| Cast request to a redirect | `{type: 'redirect', url}` (an XHR cannot follow a login redirect cleanly) |
| Cast request that fails | HTTP status + `{status: 'error', msg, data: {type: 'error', code}}`; a 503 (maintenance) is `type: 'reload'` |

The envelope follows the app-wide `{status, msg, data}` shape:

```json
{ "status": "success", "msg": "",
  "data": { "type": "partial", "target": "#cast-view", "title": "App | Items", "html": "<section>...</section>",
            "css": ["/css/items/items.css?v=..."], "js": ["/js/items/items.module.js?v=..."], "own": ["...the page's own files..."],
            "guard": "private", "url": "/items", "page": "items.items", "modalClass": null, "form": null, "csrf": "..." } }
```

- **`type`** is chosen by `View`: `partial` fills a container (`target`, default `#cast-view`); `page` replaces the whole body (used when the page's `authguard` is not the one the client sent in
  `X-Cast-Guard`, e.g. after login, so the layout can change, or when the client asks for `X-Cast-Type: page`); `modal` opens a modal.
- **A fragment** is a view that is only a piece of page. Return it with `'fragment' => true` (any container, `X-Cast-Target`), or with `'type' => 'modal'` plus `modalClass` / `form`;
  these do not need `spa`. A browser that requests such a URL directly gets the bare view.
- **Assets:** `css` / `js` are the files `__modules()` would link for the page (`resources/css/{parent}/{page}.css`, `resources/js/{parent}/{page}.module.js`, and the same for `tabName`),
  so a swap loads what a full load would. `own` marks the page's own files; the client removes them when it leaves the page. On a full-body swap the lists come from the rendered layout.
- `title` is `$data['title']`, else the rendered `<title>`, else "App | Page name". `csrf` is the current token (it changes at login).

### The client (`Cast`)

Plain JavaScript, no dependencies. Links and forms are picked up automatically.

```html
<a href="/items">Items</a>                                   <!-- same-origin link on a Cast page: swaps the content, updates the URL and title -->
<a href="/report.pdf" download>PDF</a>                       <!-- download, target=_blank, modifier keys, other origins: normal -->
<a href="/legacy" data-cast="off">Old page</a>               <!-- opt out: a full load -->
<a href="/items/5/edit" data-cast="modal">Edit</a>           <!-- open the response in a modal; data-cast="page" swaps the whole body -->
<a href="/items/top" data-cast-target="#side">Top</a>        <!-- fill another container -->
<form method="post" action="/items" data-cast-form>          <!-- submitted with fetch; 422 errors appear under the fields -->
    <?= __csrf() ?>
    <input name="name"><p data-cast-message role="alert"></p>   <!-- other errors (e.g. a wrong password) go here -->
</form>
```

```js
Cast.load("/items?page=2");                         // load into the content area (type: "partial" | "page" | "modal", target, push, replace)
const res = await Cast.http({ url: "/items/5", type: "PUT", data: { qty: 3 } });   // JSON, CSRF header, resolves with {status, msg, data}
if (res.status === "success") Cast.load(location.pathname, { push: false });       // refresh the current page

// resources/js/items/items.module.js: loaded once, mount() runs on every visit, destroy() when the page is left
Cast.page({
    mount(ctx) {
        ctx.on("click", ".js-delete", async (event, button) => { /* delegated, removed automatically on leave */ });
        // ctx.el = the container, ctx.signal aborts on leave (pass it to fetch)
    },
    destroy(ctx) {},
});
```

- `Cast.http` accepts `url, data, type|method, isform, busy, follow, headers`; it always resolves (network and server errors become `{status: 'error', msg}`), follows redirect envelopes, and updates the CSRF token.
  Replace it with `Cast.configure({ http: yourAxiosWrapper })`, and open modals your own way with `Cast.configure({ modal: (envelope) => ... })`.
- Under a sub-folder (`APP_BASE_PATH=/my-app/public`) root-relative URLs given to `Cast.http` and `Cast.load` get the folder put in front automatically; `Cast.url("/items")` does the same by hand (for `fetch`, `<img src>`).
- **Accessibility:** after each navigation the client moves focus to the first heading of the new content and announces the page title in a polite live region (`#cast-announcer`); a failed load shows an alert block with a **Try again** button.
- **Events** (bubbling, native `CustomEvent`s; `event.detail` has the data, in jQuery use `event.originalEvent.detail`): `cast:mounted` (every container that was filled, also modals), `cast:destroy`, `cast:navigate`,
  `cast:saved` (a `data-cast-form` succeeded), `cast:invalid` (422), `cast:error`. Initialise widgets (date pickers, selects) on `cast:mounted` instead of on `DOM ready`.
- History: `pushState` / `popstate` with scroll restore, one request in flight at a time (a new click cancels the old one), a normal page load as the fallback for anything that is not a Cast answer.
- **Rules for page scripts:** use `Cast.page()` instead of `$(document).ready` (it would run only on the first visit); inline `<script>` blocks inside swapped HTML are not run (page code belongs in the page module, data in attributes); same-origin assets only.
- Without JavaScript the shell shows a `<noscript>` note; set `'initial' => 'inline'` in `config/spa.php` to render the first page's content on the server.

`config/spa.php`: `enabled` (`true`; `false` turns the whole layer off), `initial` (`lazy` | `inline`), `root` (`body`), `view` (`#cast-view`). Override the skeleton with `resources/views/spa/skeleton.cast.php`,
and the colours with the `--cast-*` CSS variables.

## JSON API

For a front-end framework (Next.js, Vue, React, a mobile app) or any other server. Put the routes in **`routes/api.php`**: they are registered under `/api` (`config('api.prefix')`), so
`Router::get('/items', ...)` there answers `GET /api/items`. Everything under the prefix is JSON in and out, with the same `{status, msg, data}` shape; there is no HTML and no redirect, whatever the client sends.

```php
// routes/api.php
use Cast\Core\Router;
use Cast\Http\Middleware\{ApiAuth, Throttle};

Router::post('/auth/token', [TokenController::class, 'issue'])->use([Throttle::class, 10, 1]);   // email + password => token

Router::middleware([ApiAuth::class], function () {                                               // a valid token (or a login session)
    Router::get('/me', [TokenController::class, 'me']);
    Router::middleware([ApiAuth::class, 'items:write'], function () {                           // the token must have this ability
        Router::post('/items', [ItemsController::class, 'store']);
        Router::delete('/items/{id}', [ItemsController::class, 'destroy'])->middleware(['manage-items']);   // + a permission of the user
    });
});
```

| Status | When |
| --- | --- |
| 200, 201 | `Response::success($msg, $data, $status)` (a returned array is also sent as JSON) |
| 401 | No credentials, or a bad / revoked / expired token (`WWW-Authenticate: Bearer`) |
| 403 | A missing ability or permission |
| 404, 405 | Unknown path, wrong verb (`Allow` header) |
| 419 | A cookie-authenticated write without the CSRF token (see below) |
| 422 | Validation: `{status: 'error', msg, data: {errors: {field: 'message'}}}` |
| 429 | Rate limit (`Retry-After`, `X-RateLimit-*`) |
| 500 | `{status: 'error', msg}`; the real message only when `APP_DEBUG=true` (it is always logged) |

### Tokens

A token is `{id}|{secret}`: the id finds the record, and **only the SHA-256 of the secret is stored**, so a leaked table does not leak usable tokens (compared with `hash_equals`).
The plain token is shown once, when it is created. Each token has a name, abilities, an optional expiry and a last-used time, and can be revoked.

```php
$issued = app('tokens')->issue($user['id'], 'mobile app', ['items:read', 'items:write'], ttl: 30 * 86400);
$issued['token'];                       // "9f3c1a2b4d5e6f70|k3J..." : give this to the client, once
app('tokens')->revoke($issued['id']);   // or ->revokeAllFor($user['id'])
```

Abilities are strings you choose: `*` (everything), `items:*` (a group) or `items:read` (one). A token can only do what its **user** may do **and** what its abilities allow.
`app('auth')->verify($email, $password)` checks credentials without starting a session, for the login route.

Setup: create the table once (`php cast token:schema --migration` then `php cast migrate`, or `--run`, or `ApiTokenSchema::sql($driver)`), and make your user store also implement
`Cast\Contracts\FindsUsersById` (`findById($id): ?array`), because a token only holds the user's id. Keep tokens elsewhere by binding your own `Cast\Contracts\TokenStore` as `token_store`.
Console: `token:create <login> [--name=] [--abilities=a,b] [--days=N]`, `token:revoke <id>`, `token:schema [--run]`.

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/token -H 'Content-Type: application/json' \
        -d '{"email":"admin@example.com","password":"password"}' | jq -r .data.token)
curl -s http://localhost:8000/api/items -H "Authorization: Bearer $TOKEN"
curl -s -X POST http://localhost:8000/api/items -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"name":"Bolt","qty":10,"price":1.5}'
```

```js
// a front end (Next.js, Vue, ...) : no cookies, no CSRF token, the token in a header
const api = (path, init = {}) => fetch(`${API}/api${path}`, { ...init, headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}`, ...init.headers } }).then((r) => r.json());
const { data } = await api("/items?page=1&per_page=20");
```

### CSRF and cookies on the API

CSRF protects requests that carry **ambient credentials** (the session cookie). So on API routes the token is **not** required for a request that is authenticated by a bearer token, or that has no
session cookie at all; it **is** required (419) for writes from a browser that sends the session cookie. A front end on another origin that uses cookies instead of tokens needs
`CORS_ALLOWED_ORIGINS=https://app.example.com`, `fetch(..., { credentials: "include" })`, `COOKIE_SITE=None` with `COOKIE_SECURE=true`, and the `X-CSRF-TOKEN` header; bearer tokens avoid all of that.

### Rate limiting and CORS

`[Throttle::class, 60, 1]` allows 60 requests per minute per caller (the token, otherwise the IP address), counted across every route that uses the same limit (add a third argument, `[Throttle::class, 5, 1, 'login']`, for a separate counter; counters are files in `storage/framework/throttle`), adds `X-RateLimit-Limit/Remaining/Reset`, and answers 429 with `Retry-After`.
Apply it to a route with `->use([...])`, to a group with `Router::middleware([...])`, or to the whole API with `'middleware' => [[Throttle::class, 60, 1]]` in `config/api.php`. Behind a proxy make sure `REMOTE_ADDR` is the client address.
CORS: only exact origins in `CORS_ALLOWED_ORIGINS` get headers (`Authorization`, `X-CSRF-TOKEN` and the verbs including PATCH are allowed; the rate-limit headers are exposed); `OPTIONS` preflight is answered before authentication.

## Editor support

The package ships a VS Code extension (`editor/vscode`) for `.cast.php` views. Install it from your project:

```bash
php cast editor:install        # VS Code, Insiders, VSCodium, Cursor, Antigravity, Windsurf: every one it finds
```

Then reload the editor window. It gives you:

- **Colours** for component tags (`<Card>`, `<Btns.Button />`), `<Slot name="...">`, props (`title="Hi <?= $name ?>"`, `total={$qty + 1}`, `{...$data}`) and components inside `{ }` values. The file stays a normal PHP file,
  so PHP IntelliSense, Emmet and the HTML features keep working.
- **Ctrl+click** (Cmd+click on macOS), **F12** and **hover** on a component tag, a view name (`__includes('layouts.header')`, `views('home.home')`), a legacy `Component('btns.add')` or a module
  (`__modules('app.app', 'js')`): the file opens, using the same rules as the engine (`<Form.TextInput>` is `form/text-input`, `form/text_input`, `form/TextInput` or `form/textInput`).
- **Snippets**: `ccomp`, `cslot`, `cprop`, `cfor`, `cif`, `cpage`, `cinc`, `cmod`, and `cpagejs` in JavaScript.

`--editor=code|insiders|vscodium|cursor|antigravity|windsurf` picks one editor, `--dir=PATH` installs into any extensions folder, `--uninstall` removes it. The folders it searches for components
come from the `cast.componentsPath`, `cast.viewsPath` and `cast.resourcesPath` settings (and `config/view.php`). Details: [editor/vscode/README.md](editor/vscode/README.md).
A Marketplace extension (with prop completion, diagnostics and rename) will follow as the framework grows.

### Component docs: props, types, agents

Write a docblock in a component file (`@var string|null $label The text to show`) and the extension shows it on hover, completes props and values, and warns about unknown or missing props. `php cast components --json` gives the same information to AI tools, and `php cast make:component` starts new components documented. See [docs/COMPONENTS.md](docs/COMPONENTS.md).

## Helpers

Installing the package loads these global functions (each wrapped in `function_exists`, so you can define your own first). Names are unchanged from the apps they came from.

| File | Contents |
| --- | --- |
| `core.php` | `app`, `env`, `config`, `base_path`, `storage_path`, `resource_path`, `public_path`, `useConfig`, `__getConfig`, `views`, `Component`, `__includes`, `__modules`, `__cast`, `render404`, `route`, `route_to`, `abort`, `_access`, `file_control`, `app_version`, `sendAlert`, `__getAlerts`, `__busyLoader`, `clearState`, `__getSess`, `dd`, `dump`, `vd`, `__prev` |
| `request.php` | `request`, `response`, `getPost` |
| `security.php` | `__csrf`, `__verifyCsrf`, `hashPassword`, `verifyPassword`, `__randStr`, `tokenGen`, `__getUser`, `_checkAccess` |
| `strings.php` | `__ucwords`, `__ucfirst`, `str_capitalize`, `snakeCase`, `htchars`, `str_escape`, `htmlNewLine`, `strReplace`, `str_addHyphen`, `__getSplitStr` |
| `arrays.php` | `arrayToList`, `__implode`, `arraySearch`, `searchMultiArray`, `arrayUnique`, `arrayRand`, `arrayMultiToSingle`, `arrayReducer`, `add2dArray`, `sortArray`, `sortMultiArray`, `paginateArray`, `parseArray`, `decodeJsonInArray` |
| `dates.php` | `getDateTime`, `__fixDate`, `dateDiff`, `modifyDate`, `getMonthLastDay`, `getFirstLast_monthDate`, `getMonthsInRange`, `getYearMonths`, `__useMonth`, `__dueIn` |
| `math.php` | `__round`, `__floats`, `__compare`, `isMultiple`, `getFloat`, `__money`, `__symbolsCurr` (formats via `config('money.formats')`) |
| `html.php` | `jsonQuotes`, `jsonValidate`, `__attr`, `__requiredAttr`, `__selectedValue`, `__getImg`, `__invalidFeedback`, `__textAlign` |
| `validation.php` | `validate`, `formValidation`, `validateParam`, `checkRouteParams`, `checkPostParams`, `checkValidity` |

**Your own helpers (business logic):** put `*.php` files in a folder and point `config/helpers.php` at it: `return ['custom' => 'app/helpers'];`.
They load at boot, after the framework's.

## Static files

Before routing, `/css/...`, `/js/...`, `/styles/...`, `/fonts/...`, `/images/...` and `/public/...` are served from the folders in `config('static')`:

```php
'css'    => ['dir' => 'resources',            'keep_prefix' => true],    // /css/app.css     => resources/css/app.css
'public' => ['dir' => 'public/assets/vendor', 'keep_prefix' => false],   // /public/lib/x.js => public/assets/vendor/lib/x.js
```

Only known file types are served (never `.php`), the resolved path must stay inside its folder (`realpath` check, so `../` tricks return 404), the Content-Type comes from the
extension, `?v=` URLs are cached for a year, others revalidate (`If-Modified-Since` gives 304). Static files are answered before the application boots.
Every response (static or not) carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and `Permissions-Policy`. HSTS is left to the web server.
CORS headers are sent only for exact origins in `CORS_ALLOWED_ORIGINS`, never `*`, and preflight `OPTIONS` requests get a 204.

## Errors, maintenance and updates

**Error pages.** `abort(404)`, a missing route, a wrong verb, a bad CSRF token and a permission failure all end in an error page (HTML) or `{status:'error', msg}` (JSON clients).
The default is one small, self-contained page (no external files, dark-mode aware) in `Views/errors/error.cast.php`. Override it with `resources/views/errors/error.cast.php`,
or per code with `errors/404.cast.php`, `errors/419.cast.php`, ... The view gets `$code`, `$title`, `$message`, `$appName`, `$homeUrl`. Uncaught exceptions go to
[`anode/error-handler`](https://github.com/arnoldduo2/error-handler) (logging and its error screen) on web requests; set `'error_handler' => false` to turn it off.

**Maintenance mode.**

```bash
php cast down --message="Back at noon" --secret=letmein --retry=120   # 503 page for everyone except...
php cast down --in=60                                                  # or schedule: down automatically in 60 seconds
php cast up
```

State is a JSON file (`storage/framework/maintenance.json`). A request passes with the header `X-Maintenance-Secret`, the query `?maintenance_secret=`, or the
`cast_maintenance` cookie (the SHA-256 of the secret). The secret is stored hashed. `config('maintenance.bypass')` can be a callable `fn(Request $r): bool` (e.g. allow admins).
Override `views/maintenance.cast.php` for your own page. Static files are still served. Keep the state somewhere else (a database table) by binding your own
`Cast\Contracts\MaintenanceStore` into a `MaintenanceManager`.

**Updates.** Bind an `updater` (`Cast\Contracts\Updater`: `currentVersion()`, `latestVersion()`, `needsUpdate()`, `run()`; `CallbackUpdater` builds one from closures). When it
reports a pending update the app shows `views/updating.cast.php` (503, `Retry-After: 30`), or with `'auto_update' => true` runs `run()` first and carries on if it succeeds.

## Documentation site

`php cast serve`, then open **`/cdocs`**: this documentation as a browsable site (search with `/`, a dark and a light theme, copy buttons, a list of the headings of each page). It is two static files made from the markdown by `php cast docs:build`;
use the same command for your own project's docs (front matter and `docs/_meta.json` say where each page goes). It is served while `APP_ENV` is not `production`. See [Writing the docs](docs/WRITING-DOCS.md).

## Console

```bash
php cast                          # list commands
php cast help migrate:sync        # arguments, every option and examples for one command
php cast migrate:sync --help      # the same (-h works too); the command is not run
php cast help --markdown --write=docs/commands.md    # the whole reference as a file
```

Output is coloured on a terminal (green commands, blue arguments, orange headings; `NO_COLOR=1` turns it off, `FORCE_COLOR=1` forces it on, pipes and files are plain). The full reference for every command and flag is in [docs/COMMANDS.md](docs/COMMANDS.md) (generated by the last line above).

| Command | Purpose |
| --- | --- |
| `serve [--host=127.0.0.1] [--port=8000] [--dry]` | PHP's built-in server for `public/` |
| `route:list` | Every route, handler and middleware |
| `views:clear` | Delete compiled views |
| `down [--message=] [--secret=] [--retry=] [--in=]` / `up` | Maintenance mode |
| `env:check` | Checks PHP, extensions, `.env`, debug in production, writable `storage/`, folders |
| `make:config <name>`, `key:generate` | A documented config file for a section; the app key |
| `make:service <Name> [--example=printer\|barcode\|qrcode]` | A service class, blank or a working printer / barcode / QR example |
| `requirements [--production] [--json]` | Does this PHP have the extensions, limits and settings the framework needs |
| `make:module <Name> [--core] [--model]`, `modules:list`, `modules:check`, `modules:table --migration`, `modules:enable <name>`, `modules:disable <name>` | [Modules](#modules-optional-gating) |
| `deploy:init`, `deploy:check`, `deploy:scan`, `deploy:optimize` | [Deploying to production](#deploying-to-production) |
| `demo:strip [--pack=clean\|shell\|crud\|auth\|auth-crud]` | Remove the demo and keep a starter pack |
| `make:controller`, `make:model`, `make:middleware`, `make:command`, `make:rule` `<Name>` `[--force]` | Class from a stub in `app/` (`Admin/User` makes a sub-folder) |
| `token:create <login> [--name=] [--abilities=] [--days=]`, `token:revoke <id>`, `token:schema [--migration\|--run]` | API tokens |
| `init [--demo] [--no-migrate] [--force]` | Create a new app's files in the current folder (`--demo`: the starter, migrated and seeded) |
| `make:migration`, `migrate [--seed --pretend --step --force]`, `migrate:baseline`, `migrate:sync`, `db:sequence`, `components`, `make:component`, `migrate:rollback [--step=N]`, `migrate:reset`, `migrate:refresh`, `migrate:fresh`, `migrate:status` | [Migrations](#migrations) |
| `make:seeder`, `db:seed [--class=]` | Seeders |
| `editor:install [--editor=] [--dir=] [--uninstall]` | Install the VS Code extension for `.cast.php` views |
| `ide:helpers [--path=]` | Write `_ide_helpers.php` so the editor knows the global helpers (fixes "Undefined function 'htchars'") |
| `views:check [--fix]` | Find views that start with `declare(strict_types=1);` (PHP refuses it inside a template) |
| `docs:build [--source --readme --out --check]` | Build the documentation site (`data.js` + viewer) from markdown ([Writing the docs](docs/WRITING-DOCS.md)) |
| `help [command]`, `<command> --help` | Usage, arguments, options and examples ([docs/COMMANDS.md](docs/COMMANDS.md)) |
| `version` | Framework and PHP versions |

Your own commands extend `Cast\Console\Command` and are listed in `config/console.php`: `return ['commands' => [App\Console\Commands\SyncStockCommand::class]];`.
Give them help by setting `protected array $arguments = ['id' => 'The order id', 'note?' => 'Optional note'];` (a trailing `?` is optional), `$options = ['--dry' => 'Show what would happen']` and `$examples = ['php cast stock:sync --dry' => 'preview']`.
`bin/cast` uses `bootstrap/app.php` when it exists.

## Contracts

Interfaces in `Cast\Contracts` where an app plugs in its own behaviour:

| Contract | Implemented by | Used for |
| --- | --- | --- |
| `ModelContract` | `Core\Model` | The CRUD surface services rely on |
| `Savable`, `Patchable`, `HasLineItems` | your models | `ModelPersister` |
| `Middleware` | your middleware, `Csrf`, `Authenticate`, `Maintenance` | Route middleware |
| `Guard` | `SessionGuard` (default) or your own | Who is logged in and what they may do |
| `UserProvider` | your user store | `Auth` |
| `FindsUsersById` | your user store (next to `UserProvider`) | Turning an API token back into a user |
| `TokenStore` | `DatabaseTokenStore` (default) or your own | Where API tokens are kept |
| `Rule` | your validation rules | `Validator` |
| `ViewRenderer` | `Core\View` | The template layer |
| `MaintenanceStore` | `FileMaintenanceStore` (default) | Where maintenance state lives |
| `Updater` | `CallbackUpdater` or your own | Pending updates |
| `Command` | `Console\Command` | Console commands |

## Modules (optional gating)

Off by default. Turn it on (`CAST_MODULES=true`, or `modules.enabled` in `config/modules.php`) and every group of routes can sit behind a **module gate**, so a part of the app that is switched off or not built yet answers with a "module inactive or unavailable" page instead of breaking the app.

```bash
php cast make:config modules            # config/modules.php: list your modules as core or optional
php cast make:module Reports            # controller, views, routes/modules/reports.php, and its entry in config/modules.php
php cast make:module Billing --core --model
php cast modules:list                   # every module: core/optional, active / inactive / unbuilt
php cast modules:check                  # exit 1 when a core module is not active (deploy:check runs it too)
php cast modules:disable reports        # switch an optional module off (storage/framework/modules.json)
php cast modules:enable reports
```

```php
// config/modules.php
'enabled' => env('CAST_MODULES', false),
'core'     => ['billing' => ['title' => 'Billing', 'requires' => [App\Controllers\BillingController::class]]],   // the app cannot work without them
'optional' => ['reports', 'printing' => ['requires' => [App\Services\PrinterService::class]], 'archive' => ['active' => false]],

// routes/modules/reports.php (every file in routes/modules/ is loaded for you)
Router::module('reports', function () {            // = Router::middleware([ModuleGate::class, 'reports'], ...)
    Router::get('/reports', ReportsController::class);
});
```

**Your own rules (plans, licences, tenants).** The framework does not know about plans: Essentials / Professional / Enterprise is app code. Add a rule with `Modules::resolveUsing()` in a service provider; it is called for each module before the framework's own checks and returns `null` (carry on) or a status that replaces them:

```php
app('modules')->resolveUsing(fn(array $m) => app('plans')->allows($m['options']['tier'] ?? '') ? null : [
    'state' => 'locked', 'reason' => 'Part of a higher plan.', 'http' => 403, 'fault' => false,
    'message' => 'Not part of your plan.', 'headline' => 'Not in your plan', 'detail' => '...', 'action' => ['label' => 'See the plans', 'url' => '/plans'],
]);
```

`state` is any word, `http` the status code (default 503), `fault => false` makes `modules:check` and `deploy:check` leave it alone, `message` is the JSON text, `headline` / `detail` / `action` fill the fallback page. Every extra key in a module's config array (`'payroll' => ['tier' => 'professional']`) arrives as `$m['options']`; `app('modules')->options('payroll')` reads it anywhere. A ready-made plan service for a cast-app is in the app's repo, not here.

**Controlled by the database.** Set `'store' => 'database'` and run `php cast modules:table --migration` then `php cast migrate`: the `modules` table has one row per module (`name`, `enabled`: 1 on, 0 off, NULL follows the config), so an admin screen can switch modules with plain updates (`Cast\Core\Modules\DatabaseModuleStore::set()`). Rows are read once per request, and a missing table means "no opinion", so the app keeps running while you migrate. Another storage (a licence server, a multi-tenant table) is a class implementing `Cast\Contracts\ModuleStore` bound as `module_store`.

A module is **active**, **inactive** (`'active' => false`, a function returning a bool, `modules:disable`, or your own `module_store`), **unbuilt** (a class or file in its `requires` list does not exist yet) or **unregistered** (the gate names a module that is not listed), or a state your own rule returns. Anything but active answers `503` with the page `errors/module` (the framework has one; make `errors/module.cast.php` in your views to replace it), `Retry-After` set, and the usual JSON envelope for APIs and the SPA client. **Core** modules are the ones the app cannot work without: they cannot be switched off, `modules:check` and `deploy:check` fail when one is not active, and optional ones only warn. Hide a menu item while its module is off with `module_active('reports')`. To keep switches in a database implement `Cast\Contracts\ModuleStore` (`isEnabled($module): ?bool`, `set()`) and bind it as `module_store`. With gating off every gate lets everything through, so the same code runs either way.

## Deploying to production

Three commands take an app from your machine to a server:

```bash
php cast deploy:init          # asks: public address, cookie domain, SameSite, database host/name/user/password, allowed front ends
                              # writes .env.production (mode 600): APP_ENV=production, APP_DEBUG=false, a NEW APP_KEY, secure cookies on https
# on the server
composer install --no-dev --optimize-autoloader
cp .env.production .env       # keep it out of git
php cast migrate --force
php cast deploy:check         # settings, cookies, CORS, folders, database, leftover debugging, PHP: exits 1 on a problem
php cast deploy:optimize      # clears compiled views and lists the OPcache / autoloader speed-ups
```

`deploy:init` starts from your `.env`, so your own keys are kept, and answers can come from options (`--url=`, `--cookie-domain=`, `--same-site=`, `--db-conn=`, `--db-host=`, `--db-name=`, `--db-user=`, `--db-pass=`, `--cors=`, `--keep-key`, `--file=`). It refuses `SameSite=None` without https and `*` as a CORS origin. The web server's document root must be `public/`.

**Leftover debugging.** `deploy:check` (and `php cast deploy:scan` on its own) reads your code and fails on `dd()`, `dump()`, `vd()`, `var_dump()`, `print_r()`, `var_export()`, `phpinfo()`, `debug_print_backtrace()` in PHP and views, and on `console.log/debug/info/warn/table/trace/...` and `debugger` in your JavaScript (also inline `<script>` blocks). `console.error()` is allowed, and so are `print_r($x, true)`, methods named `dump()`, and anything in a comment or a string. Third-party files (`vendor/`, `public/assets/vendor`, `*.min.js`) are not read. To keep one on purpose put `cast:keep` in a comment on that line or the line above; to debug in production in general use `php cast deploy:check --allow-debug`, `DEPLOY_ALLOW_DEBUG=true` in `.env`, or `deploy:init --allow-debug` (it asks too).

`php cast requirements [--production]` checks PHP itself: version, the extensions the framework needs (`pdo`, `mbstring`, `json`, `openssl`, `ctype`, `session`, and the PDO driver of your database), nice-to-haves (`sodium`, `gd`, `zip`, `intl` ...), `memory_limit`, upload sizes and, with `--production`, `display_errors`, `expose_php`, OPcache and strict sessions. `deploy:check` includes it.

## Security notes

- Every value in the query builder is bound; identifiers are validated. Raw SQL goes through `raw()` with bound parameters.
- Escape output: `<?= htchars($value) ?>`. `<?= ?>` prints raw, and `Request` input is raw too.
- Compiled views are PHP code: keep `storage/` outside the web root, or deny web access to it. Only render templates you trust.
- Set `APP_DEBUG=false` in production (`php cast env:check` warns when it is not).
- Passwords: `Auth::hash()` uses Argon2id (bcrypt as a fallback); the session never stores the hash; a new session id and CSRF token are issued at login.
- Keep `.env` out of git. The starter ships `.env.example` only.
- API tokens: only a SHA-256 of the secret is stored; give each client its own token with the fewest abilities it needs, set an expiry, and revoke tokens that leak.
  Send them over HTTPS only. Rate-limit the login route.
- The SPA client inserts server-rendered HTML (the same trust as a normal page); it sets titles and error text with `textContent` and loads same-origin scripts and styles only.

## Testing

```bash
composer install
composer test                          # PHPUnit: every case, named "<file>: <case>"
composer test -- --filter migrations   # only the cases whose name contains "migrations"
composer test -- --testdox             # one readable line per case
composer test:plain                    # the same cases with the dependency-free runner (no PHPUnit needed): php tests/run.php [filter]
```

Both runners execute the same cases (`tests/cases/*.php`, written as `test('name', fn)` with small helpers). A failing case is reported by name in both. `tests/phpunit/` is the bridge that hands each case to PHPUnit.

Against a real database server (the suite creates and drops tables in that database, so use a scratch one):

```bash
CAST_TEST_DB=mysql CAST_TEST_DB_HOST=127.0.0.1 CAST_TEST_DB_NAME=cast_test CAST_TEST_DB_USER=root CAST_TEST_DB_PASS=secret composer test
CAST_TEST_DB=pgsql CAST_TEST_DB_USER=postgres CAST_TEST_DB_PASS=secret composer test      # CAST_TEST_DB_PORT is optional
```

The suites cover each subsystem and the starter app end to end (login, CSRF, JSON verbs, validation, maintenance, error pages, the SPA envelopes, API tokens, rate limits, CORS).

Browser checks for the SPA client run in Chromium with [Playwright](https://playwright.dev) against the starter app (Playwright is not a dependency of the package):

```bash
cd starter && composer install && rm -f storage/database.sqlite && php cast migrate --seed
php -S 127.0.0.1:8099 -t public public/index.php &
cd .. && npm i playwright && npx playwright install chromium
BASE=http://127.0.0.1:8099 node tests/e2e/spa.e2e.js
```

## Upgrading

[docs/UPGRADING.md](docs/UPGRADING.md): `composer require anode/cast-framework:^0.3` (on 0.x, `^0.2` stays on 0.2.x), `php cast init --demo --force --no-migrate` to refresh the demo files, `php cast migrate:baseline` to adopt migrations on an existing database.

## Versioning

[Semantic Versioning](https://semver.org). The public API is what this README documents: the helper names, the config keys, the contracts, the route/middleware spec,
the response shapes and the console commands. See [CHANGELOG.md](CHANGELOG.md). Migrating an existing app: [docs/ERP-PORTING.md](docs/ERP-PORTING.md).

## License

MIT
