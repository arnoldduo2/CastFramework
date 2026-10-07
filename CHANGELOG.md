# Changelog

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 minor versions may change behaviour.

## 0.5.0

- **`php cast init` asks questions** (or takes options; `-n` uses the defaults): the **source folder** (new default `src`, was `app`; existing apps are not affected, their `config/app.php` says `app`), **web or API**, **how the front end works** (the built-in SPA client, server pages with normal loads, or an API for a front-end framework with CORS), whether to use the **Anode error handler** (all its settings and defaults are listed and can be configured), and **default or custom error pages**. Options: `--source`, `--frontend=spa|php|external|api`, `--cors`, `--error-handler=yes|no`, `--error-pages=default|custom`.
- `config/app.php`: `error_handler` can be `true`, `false` or an array of the package's options (folders relative to the app); `error_pages => 'custom'`: an **empty** view in `resources/views/errors` counts as "not built yet", the framework's page is shown, and in development the page says which view you still have to build.
- API-only apps (`--frontend=external|api`) get `routes/api.php`, a `StatusController` and no views.

## 0.4.3

- **Docs:** [docs/GETTING-STARTED.md](docs/GETTING-STARTED.md) (the workflow, where every file goes, wiring a feature, turning the demo into your own project, troubleshooting) and an index [docs/README.md](docs/README.md); the README contents are grouped by topic.
- The starter's home page explains the workflow, where everything is, and how to delete the demo; the minimal app's home page lists the path of a page. Both layouts use a fixed footer (`position: fixed; bottom: 0; border-top`, 12px, centred, `padding: 5px`) and `body { height: 100dvh }` with a scrolling page area.

## 0.4.2

- **`php cast ide:helpers`** writes `_ide_helpers.php`, the signatures of every global helper (`htchars()`, `views()`...), so editors that do not index `vendor/` stop reporting "Undefined function". `php cast init` creates it and git-ignores it. (Reproduced with the Intelephense language server: with `vendor/` unindexed every helper was undefined; with the file, none.)

## 0.4.1

- **Command help:** `php cast help <command>` (or `<command> --help` / `-h`) prints usage, arguments, every option and examples; `php cast help --markdown [--write=PATH]` writes the whole reference ([docs/COMMANDS.md](docs/COMMANDS.md)). Custom commands document themselves with `$arguments`, `$options`, `$examples`. A test fails when a command reads an option it does not document.
- **Fatal errors no longer loop.** A PHP fatal error (for example a view that starts with `declare(strict_types=1);`, which fails because the engine puts a line before every template) is now logged and answered as a normal 500: JSON for Cast and API clients (with the template's name in debug), the error page for browsers, never raw PHP output. The Cast client shows an HTTP error that has no Cast body instead of reloading the page, which looped; a page that fell back to a normal load within 5 seconds is not retried.
- `php cast views:check [--fix]` finds the views that start with `declare(strict_types=1);` and removes the line.

## 0.4.0

- **`php cast migrate:sync`**: writes migrations for tables that already exist (MySQL/MariaDB, PostgreSQL, SQLite), all tables or `[table]` / `--table=` / `--except=`, with columns, defaults, indexes, foreign keys (tables ordered by their dependencies) and collations, recorded as run. `--pretend`, `--no-record`, `--collation=`, `--auto-increment`.
- **`migrate:sync --init`** tests every relationship (declared foreign keys and `x_id` columns without one, including orphan rows) and reports PASS / WARN / BROKEN.
- Builder: `$table->charset()`, `$table->collation()`, `->collation()` on columns, `->autoIncrement()` on columns, `Schema::autoIncrement()`, `nextAutoIncrement()`, `autoIncrementColumn()`.
- **`php cast db:sequence [table] [--set=N] [--sync]`** shows auto-increment positions and fixes counters that are behind the data.

- **Component docs:** a component's docblock (`@var type $prop description`, `@slot`, `@example`, `@deprecated`) and its `??=` defaults are read as the component's spec, by the VS Code extension and by `Cast\Support\ComponentDocs`; both readers give identical JSON (shared fixtures in `tests/fixtures/components`).
- `php cast components [Tag] [--json|--markdown] [--write=PATH] [--check]` and `php cast make:component Name --props=...`; `cast init` writes an `AGENTS.md` for AI tools when none exists. The starter's Button and Card are documented. See [docs/COMPONENTS.md](docs/COMPONENTS.md).
- VS Code extension 0.2.0: prop/value/slot/tag completion, hover tables, diagnostics (`cast.diagnostics`), quick fixes, go to prop definition.

## 0.3.2

- **`php cast migrate:baseline`** (and `Migrator::baseline()` in the contract): records the pending migrations as run without running them, so an app whose tables already exist can adopt migrations without losing data.
- `cast init --force` never overwrites `.env` (it holds settings and secrets), and says so.
- New [docs/UPGRADING.md](docs/UPGRADING.md): moving an app made on 0.2.x to 0.3 (the `^0.2` constraint does not reach 0.3), refreshing the demo files, adopting migrations.

## 0.3.1

- **PHPUnit:** `composer test` runs every case through PHPUnit 10.5 (`phpunit.xml.dist`, `tests/phpunit/`), each named `<file>: <case>`; `--filter`, `--testdox`. The dependency-free runner stays (`composer test:plain`) and now lists failed cases by name at the end. CI runs PHPUnit on PHP 8.1 to 8.4 and against MySQL 8 and PostgreSQL 16, and writes the failed cases to the job summary.
- Fix: `Schema::columns()` and `tables()` return rows in a defined order (`ORDER BY ordinal_position` / `table_name`). MySQL 8 returned columns alphabetically, MariaDB in table order, so the result differed between servers (found by the MySQL CI job, reproduced on MySQL 8.0).

## 0.3.0

- **Migrations:** `Schema` / `Blueprint` builder with MySQL, PostgreSQL and SQLite grammars; the `Migrator` (batches, rollback, reset, refresh, fresh, status, pretend, lock file, transactional DDL where supported);
  seeders; commands `make:migration`, `migrate`, `migrate:rollback|reset|refresh|fresh|status`, `make:seeder`, `db:seed`, `token:schema --migration`. Production runs need `--force`.
- **Another ORM:** `Cast\Contracts\Migrator` (bind your own as `migrator`), `config('database.connection')` (a callable that returns your PDO), [docs/ORM-ADAPTERS.md](docs/ORM-ADAPTERS.md).
- `cast init --demo` now creates the starter's tables with real migrations and seeds the demo user (`--no-migrate` to skip); the starter's `AppServiceProvider` no longer creates tables.
- Fix: `QueryBuilder::insert()` on PostgreSQL for a table without a generated key (such as the API tokens table) no longer throws; it returns 0.
- **Editor support:** a VS Code extension in `editor/vscode` (highlighting for component tags, props, slots and `{ }` expressions; Ctrl+click / F12 / hover to open components, views, legacy components and modules; snippets) installed with `php cast editor:install`; tokenisation tests against VS Code's own PHP/HTML grammars.
- Fix (client): an asset is the same asset whatever its `?v=` version, so a script is not loaded twice when a full-body swap brings the same file with a new version.
- Tests run against real PostgreSQL and MySQL/MariaDB servers too (`CAST_TEST_DB`), and the CI has jobs for them.

## 0.2.2

- The framework maps the app's namespace (`app.namespace` => `app.source_path`, default `App\` => `app/`) itself when Composer's autoloader does not know it, so a new app works before `composer dump-autoload` (fixes "Class App\Providers\AppServiceProvider not found" right after `cast init --demo`).
- **`php cast <command>`**: a Composer plugin in the package creates the `cast` launcher in the project root on install/update (never overwrites), so `php cast init` is the first command; `init` and the starter also provide it. Works from any folder; `php vendor/bin/cast` remains as a fallback when plugins are not allowed. The package type is now `composer-plugin`; allow it with `composer config allow-plugins.anode/cast-framework true`.
- Docs, stubs and the starter use `php cast ...`.

## 0.2.1

Found by installing the app from GitHub and using it.

- SPA: the content container no longer changes the page layout (`display: contents`; cards inside it kept no gap before).
- SPA: focus moves to the new content and the page title is announced after a navigation; failed loads show a **Try again** button.
- SPA: `Cast.url()`, and `Cast.http` / `Cast.load` add the app's base folder (`APP_BASE_PATH`) to root-relative URLs; `__cast()` passes it as `data-cast-base`.
- **`php vendor/bin/cast init [--demo] [--force]`**: creates a new app's files after `composer require anode/cast-framework` (the starter now ships in the package for `--demo`).
- Error pages no longer repeat the title as the message.
- `ApiAuth` can be nested: the token is checked once and inner uses only add ability checks.
- Starter: dark-mode link contrast, wrapping header on phones, one page load (not two) after a form redirect.
- README: Windows / XAMPP / sub-folder setup. Tests: 220 server-side, 19 browser checks, also run under a sub-folder.

## 0.2.0

SPA layer and JSON API.

- **SPA:** `View::respond()` decides full page, partial or modal. Pages opt in with `'spa' => true`; the first load is the layout with a skeleton (`spa.initial` = `lazy`) or the real
  content (`inline`); Cast requests (`X-Cast-Request`) get a JSON envelope `{type, target, title, html, css, js, own, guard, url, page, modalClass, form, csrf}`, a redirect envelope or a reload.
  Fragments and modals with `'fragment' => true` / `'type' => 'modal'`. `views()` follows the same rules. New helper `__cast()`.
- **Client** (`/cast/cast.module.js`, `/cast/cast.css`, served from the package): link and form handling, `Cast.load`, `Cast.http`, `Cast.page({mount, destroy})` lifecycle with delegated listeners,
  asset loading and removal, `cast:*` events, history, modal, 422 field errors, `Cast.configure({http, modal})`.
- **API:** `routes/api.php` under `api.prefix`; always-JSON errors for the prefix (401, 403, 404, 405, 419, 422, 429, 500); `ApiAuth` middleware; hashed bearer tokens with abilities, expiry and revocation
  (`ApiTokens`, `TokenStore`, `DatabaseTokenStore`, `ApiTokenSchema`, `FindsUsersById`); `Throttle` rate limiting with `X-RateLimit-*`; CSRF is not required for bearer-token or cookie-less API requests;
  CORS exposes the rate-limit headers; `token:create`, `token:revoke`, `token:schema`.
- `Request`: `isApi()`, `bearerToken()`, `user()`, `token()`, `addResponseHeader()`; a hand-built request parses the query string of its URI; `castType()` defaults to `partial`.
- `Auth::verify()` (check credentials without a session) and `Auth::provider()`.
- Starter: SPA pages (login, items with an edit modal, stats) and a JSON API (`/api/auth/token`, `/api/me`, `/api/items`).
- Tests: server-side suites for both, and Playwright browser checks (`tests/e2e/spa.e2e.js`).

## 0.1.0

First release.

- `Application` (paths, container, providers), `Env`, `Config` (app files merge over framework defaults).
- `Router` with GET, POST, PUT, PATCH, DELETE, `match`, `any`, groups, middleware specs, permission slugs, 404 and 405.
- `Request` (JSON and form bodies for every verb, method override) and `Response` (`{status, msg, data}` helpers).
- Global CSRF token, checked for POST, PUT, PATCH and DELETE.
- Validation: string and object rules, custom rules, legacy pipe syntax, 422 JSON or flash-and-redirect.
- `Auth`, `PasswordPolicy`, `ModelPersister`, `SessionGuard`, `Authenticate`, contracts for extension points.
- `Model` and a bound, validated `QueryBuilder`; `Database` for MySQL, PostgreSQL and SQLite.
- `View` on CastTemplateEngine with auto-loaded page CSS and JS, legacy components, shared data.
- Static file serving with traversal protection, security headers, CORS for exact origins.
- Error pages, maintenance mode (file store), update hook.
- Console: `serve`, `route:list`, `views:clear`, `down`, `up`, `env:check`, `version`, `make:*`.
- Helpers by category with an app `custom` folder; `starter/` app.
