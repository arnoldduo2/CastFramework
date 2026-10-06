# CastFramework

A small PHP MVC framework for apps that render pages on the server and enhance them with plain JavaScript and jQuery.
It is built on [CastTemplateEngine](https://github.com/arnoldduo2/CastTemplateEngine) (component tags in `.cast.php` views).

- Routing for **GET, POST, PUT, PATCH and DELETE**, with route groups, middleware and permission checks.
- A `Request` / `Response` pair, one **global CSRF token** checked centrally for every state-changing verb.
- A validation system (string rules, rule objects, closures) plus the old pipe syntax for legacy code.
- Sessions with flash data, an `Auth` service, a query builder that binds every value, a small `Model`.
- Views with auto-loaded page CSS and JS, error pages, maintenance mode, an update hook, a console (`php cast`).
- No ERP or business logic: it holds only what every app needs. See [docs/ERP-PORTING.md](docs/ERP-PORTING.md) for what was left out.

Requires PHP 8.1 or newer and `ext-pdo`, `ext-mbstring`, `ext-json`.

> **Status:** version 0.x. The SPA layer (lazy-loaded pages and partials swapped in by a small JS client) is the next milestone;
> the server side is designed for it (`Request::isCast()`, `View::respond()`).

## Contents

[Install](#install) · [A first app](#a-first-app) · [Configuration](#configuration) · [Routing](#routing) · [Request and Response](#request-and-response) · [CSRF](#csrf) ·
[Controllers](#controllers) · [Validation](#validation) · [Auth and services](#auth-and-services) · [Models and the query builder](#models-and-the-query-builder) · [Views](#views) ·
[Helpers](#helpers) · [Static files](#static-files) · [Errors, maintenance and updates](#errors-maintenance-and-updates) · [Console](#console) · [Contracts](#contracts) ·
[Security notes](#security-notes) · [Testing](#testing) · [Versioning](#versioning)

## Install

```bash
composer require anode/cast-framework
```

Until the package is listed on Packagist, install it from GitHub by adding this to your `composer.json` first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/arnoldduo2/CastFramework" }]
```

The [`starter/`](starter) folder is a small working app (login, a CRUD page with `PUT`/`DELETE`, SQLite). Copy it to start a project:

```bash
cp -r starter my-app && cd my-app
cp .env.example .env
composer install            # edit the "repositories" path in composer.json if the framework is not one folder up
php vendor/bin/cast serve   # http://127.0.0.1:8000, login admin@example.com / password
```

## A first app

```
my-app/
  .env
  bootstrap/app.php         returns the Application (used by public/index.php and `cast`)
  public/index.php          the only file the web server runs
  config/                   app.php, auth.php, ...  (only list what you change)
  routes/web.php            routes (every *.php in this folder is loaded)
  app/                      Controllers/, Models/, Services/, Providers/, helpers/
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

`app/Controllers/HelloController.php` (create it with `php vendor/bin/cast make:controller Hello`):

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
| `APP_ENV` | `production` | `development` recompiles views when files change |
| `APP_DEBUG` | `false` | Debug output and fresh `?v=` asset versions |
| `APP_VERSION` | none | Asset cache-busting version (production) |
| `APP_BASE_PATH` | empty | URL folder when not at the web root, e.g. `/my-app` |
| `APP_TIMEZONE` | `UTC` | Used by `getDateTime()` |
| `APP_VIEWS_EXT` | `.cast.php` | View file extension |
| `DB_CONN`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | mysql, 127.0.0.1 | Database (`DB_CONN` may be `mysql`, `pgsql`, `sqlite`; a trailing `:` is accepted) |
| `SESSION_NAME`, `COOKIE_LIFE`, `COOKIE_PATH`, `COOKIE_DOMAIN`, `COOKIE_SECURE`, `COOKIE_HTTP_ONLY`, `COOKIE_SITE` | see `Application` | Session cookie |
| `CORS_ALLOWED_ORIGINS` | none | Comma separated exact origins |

### `config/*.php`

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
| `app.error_handler` | `true` | Register `anode/error-handler` on web requests |
| `app.namespace`, `app.source_path` | `App`, `app` | Where `make:*` commands create classes |
| `request.sanitizer` | `null` | Callable that sanitises `getPost()` data |
| `auth.session_key`, `login_path`, `home_path`, `permissions_key` | `login`, `/login`, `/`, `permissions` | Auth defaults |
| `view.ext`, `view.components` | `.cast.php`, `components` | Views |
| `static` | css, js, styles, fonts, images, public | [Static file map](#static-files) |
| `cors.allowed_origins` | `[]` | Exact origins allowed |
| `models.namespace` | `App\Models` | Where `getModelInstance()` looks |
| `helpers.custom` | `null` | Folder of your own helper files |
| `database.*` | from `.env` | `driver`, `host`, `port`, `name`, `user`, `pass`, `charset`, `dsn` |

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

`php vendor/bin/cast route:list` prints the table of routes with handlers and middleware.

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
- **Checked centrally** for every **POST, PUT, PATCH and DELETE** route, with `hash_equals`. A missing or wrong token is a **419**
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
`php vendor/bin/cast make:rule Uppercase` creates one.

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
$auth->user(); $auth->check(); $auth->logout();
Auth::hash($password);                                         // password_hash; $2a$ hashes from older apps verify too
```

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

## Helpers

Installing the package loads these global functions (each wrapped in `function_exists`, so you can define your own first). Names are unchanged from the apps they came from.

| File | Contents |
| --- | --- |
| `core.php` | `app`, `env`, `config`, `base_path`, `storage_path`, `resource_path`, `public_path`, `useConfig`, `__getConfig`, `views`, `Component`, `__includes`, `__modules`, `render404`, `route`, `route_to`, `abort`, `_access`, `file_control`, `app_version`, `sendAlert`, `__getAlerts`, `__busyLoader`, `clearState`, `__getSess`, `dd`, `dump`, `vd`, `__prev` |
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
php vendor/bin/cast down --message="Back at noon" --secret=letmein --retry=120   # 503 page for everyone except...
php vendor/bin/cast down --in=60                                                  # or schedule: down automatically in 60 seconds
php vendor/bin/cast up
```

State is a JSON file (`storage/framework/maintenance.json`). A request passes with the header `X-Maintenance-Secret`, the query `?maintenance_secret=`, or the
`cast_maintenance` cookie (the SHA-256 of the secret). The secret is stored hashed. `config('maintenance.bypass')` can be a callable `fn(Request $r): bool` (e.g. allow admins).
Override `views/maintenance.cast.php` for your own page. Static files are still served. Keep the state somewhere else (a database table) by binding your own
`Cast\Contracts\MaintenanceStore` into a `MaintenanceManager`.

**Updates.** Bind an `updater` (`Cast\Contracts\Updater`: `currentVersion()`, `latestVersion()`, `needsUpdate()`, `run()`; `CallbackUpdater` builds one from closures). When it
reports a pending update the app shows `views/updating.cast.php` (503, `Retry-After: 30`), or with `'auto_update' => true` runs `run()` first and carries on if it succeeds.

## Console

```bash
php vendor/bin/cast            # list commands
```

| Command | Purpose |
| --- | --- |
| `serve [--host=127.0.0.1] [--port=8000] [--dry]` | PHP's built-in server for `public/` |
| `route:list` | Every route, handler and middleware |
| `views:clear` | Delete compiled views |
| `down [--message=] [--secret=] [--retry=] [--in=]` / `up` | Maintenance mode |
| `env:check` | Checks PHP, extensions, `.env`, debug in production, writable `storage/`, folders |
| `make:controller`, `make:model`, `make:middleware`, `make:command`, `make:rule` `<Name>` `[--force]` | Class from a stub in `app/` (`Admin/User` makes a sub-folder) |
| `version` | Framework and PHP versions |

Your own commands extend `Cast\Console\Command` and are listed in `config/console.php`: `return ['commands' => [App\Console\Commands\SyncStockCommand::class]];`.
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
| `Rule` | your validation rules | `Validator` |
| `ViewRenderer` | `Core\View` | The template layer |
| `MaintenanceStore` | `FileMaintenanceStore` (default) | Where maintenance state lives |
| `Updater` | `CallbackUpdater` or your own | Pending updates |
| `Command` | `Console\Command` | Console commands |

## Security notes

- Every value in the query builder is bound; identifiers are validated. Raw SQL goes through `raw()` with bound parameters.
- Escape output: `<?= htchars($value) ?>`. `<?= ?>` prints raw, and `Request` input is raw too.
- Compiled views are PHP code: keep `storage/` outside the web root, or deny web access to it. Only render templates you trust.
- Set `APP_DEBUG=false` in production (`php vendor/bin/cast env:check` warns when it is not).
- Passwords: `Auth::hash()` uses `password_hash`; the session never stores the hash; a new session id and CSRF token are issued at login.
- Keep `.env` out of git. The starter ships `.env.example` only.

## Testing

```bash
composer install
php tests/run.php            # all suites (no PHPUnit needed)
php tests/run.php router     # only suites whose file name contains "router"
```

The suites cover each subsystem and the starter app end to end (login, CSRF, JSON verbs, validation, maintenance, error pages).

## Versioning

[Semantic Versioning](https://semver.org). The public API is what this README documents: the helper names, the config keys, the contracts, the route/middleware spec,
the response shapes and the console commands. See [CHANGELOG.md](CHANGELOG.md). Migrating an existing app: [docs/ERP-PORTING.md](docs/ERP-PORTING.md).

## License

MIT
