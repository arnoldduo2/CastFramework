# Changelog

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 minor versions may change behaviour.

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
