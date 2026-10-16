# Changelog

This project follows [Semantic Versioning](https://semver.org). From 1.0.0 on, what the README documents (helper names, config keys, contracts, route and middleware specs, response shapes, console commands) changes in a way that breaks apps only in a major version. **2.0.0 is in alpha** (`2.0.0-alpha`): more changes are coming before the stable release, so until 2.0.0 the API can still change between alpha versions.

## 4.1.0

Minor release: exposes CastTemplateEngine's full editor install family (extension + language server, with a
requirements check) through CastFramework's own console, as pure aliases.

- **`engine:editor-install`, `engine:editor-update`, `engine:editor-uninstall`**: new `EngineCommand` actions,
  pass-through to the matching `CastTemplateEngine\Console\CommandRegistry` entries (no logic duplicated in
  CastFramework, same pattern as every other `engine:*` alias). Installs/updates/removes both the VS Code-family
  extension and the global `cast-language-server`, with a requirements check (PHP, Node.js, npm, optional
  Phpactor for PHP-bridging) and `--yes`/`--no-phpactor`/`--dry-run`/per-editor flags. `editor:install` (the
  simpler, extension-only alias from 3.0.0) is unchanged.
- **Docs**: `docs/TEMPLATE-ENGINE.md`'s "Editor:" bullet and `README.md`'s "Editor support" section now cover
  both the simple and full install paths; `docs/COMMANDS.md` regenerated for the three new commands.

## 4.0.0

Major release integrating **CastTemplateEngine `^2.0.0`** (itself a major version, bringing the strictly-typed `@props`
directive, component inheritance, CLI AST tooling, and a standalone Language Server / Tree-sitter), plus real-world
SPA navigation fixes and a packaging fix that broke `composer install` outside this machine's own setup.

- **Upgraded to `anode/cast-template-engine` `^2.0.0`**: bumped minimum requirement in `composer.json`.
- **Strictly-Typed `@props` Directive & Inheritance**:
  - Components declare typed props with `@props { type $name [ = default ]; }` and inherit props via `@props extends::Components.Base { ... }`.
  - Updated starter, demo, and init components (`Btns.Button`, `Card`, `CrudBanner`, `Btns.AddNew`) to modern `@props` syntax and directives.
- **Component Docs & Parity**:
  - `Cast\Support\ComponentDocs` now scans both docblocks and `@props` blocks with precedence for `@props` types/defaults while preserving hand-written docblock descriptions.
  - `php cast components --check` verifies declared `@props` are used in template markup.
- **CLI Commands**:
  - Added `php cast engine:ast <file>` for inspecting template syntax tree, declared `@props`, component invocations, and scoped CSS classes.
  - Added `php cast engine:lint [--json]` (and `php cast engine:check --json`) for machine-readable JSON compiler diagnostics for IDEs and AI coding tools.
- **Editor Support & Tooling**:
  - `php cast editor:install` now supports the dedicated Language Server Protocol (`cast-language-server`) and Tree-sitter grammar (`tree-sitter-cast`) across multiple editors (VS Code, Antigravity IDE, Cursor, Windsurf).
- **Layout & SPA Resilience**:
  - Synced `@styles` in `<head>` and `@scripts` before `</body>` across starter layouts and stubs.
  - Partial SPA transitions preserve component scoped styles and scripts buffered during render.
- **Documentation**:
  - Rebuilt `/cdocs` site with new engine v2.0 guides, `@props` reference, and CLI tooling.
- **Packaging fix**: `composer.json`'s committed local path-repo (for symlinked engine development) made `composer install`
  fatally error in any environment without that exact sibling folder — every CI run, and any real `composer require
  anode/cast-framework`. Moved to local machine config instead; dropped the redundant `version` field that was failing
  `composer validate --strict` on its own warning.

## 3.0.0

Major release: the `.cast.php` editor extension and its reference docs now live solely in `anode/cast-template-engine`, which this
release requires `^1.6.0` of. **Breaking:** `editor:install --dir=PATH` is removed.

- **`editor:install` is now an alias for `engine:editor install`**: the `.cast.php` editor extension (VS Code, Antigravity IDE, Cursor, Windsurf, etc.) is built and shipped entirely by `anode/cast-template-engine`; CastFramework no longer carries its own copy of `editor/vscode` or a second install implementation. **`--dir=PATH` is removed** (the engine has no equivalent — use `--editor=NAME` or let it auto-detect). `engine:editor`'s own flags (e.g. `--editor=`) now actually reach the engine command (`EngineCommand` previously dropped them).
- **Requires `anode/cast-template-engine` `^1.6.0`** (up from `^1.5.0`).
- **Documentation pulled from the engine, not hand-copied**: `php cast docs:build` now also reads the installed `anode/cast-template-engine`'s own `README.md` and pulls its "Syntax rules", "Escaping", "Caching and errors", "Limitations", "Terminal Commands & CLI", "Documentation Snippets List" and "Coming from `useComponent()`" sections straight into this site (see `docs/_meta.json`'s `"engineReadme"`), so `php cast docs:check` now also catches drift between the two packages' docs.
- **Windows fix for `php cast serve`'s multi-worker support** (`PHP_CLI_SERVER_WORKERS`, added in 2.2.1): only enabled when the `pcntl` extension is actually available and the OS isn't Windows, instead of crashing the dev server on start.
- **Live-Reload path fix**: watched folders are resolved via `viewsPath()`/`resourcesPath()`/`routesPath()` and filtered to existing directories, instead of a hardcoded `basePath()` concatenation that could watch folders that don't exist.

## 2.2.1

Patch release adding automatic root layout wrapping via `app.cast.php`, per-view layout overrides, and fixing dev-server session concurrency.

- **Default Root Layout (`app.cast.php`)**: Page views are now automatically wrapped with `app.cast.php` (which serves `layouts.header` and `layouts.footer`), eliminating repetitive layout includes in every view file.
- **Private Layouts & Opt-Out**: Individual views can specify custom layouts via `['layout' => 'layouts.custom']` or opt out entirely with `['layout' => false]`. Existing views with complete HTML or direct header/footer calls are detected automatically to avoid double-wrapping.
- **Concurrency & Live-Reload Fix**: Replaced blocking SSE loop with non-blocking JSON timestamp polling (`<1ms`) and immediate `session_write_close()`, resolving 20-second session lock delays during dev server page loads and SPA navigations. Added multi-worker (`PHP_CLI_SERVER_WORKERS=8`) support in `php cast serve`.

## 2.2.0

Minor release adding native Node-free SSE Live-Reload / HMR, multi-app session isolation on localhost, browser error auto-clearing, and modern thin scrollbars for the framework demo.

- **Native Node-Free SSE Live-Reload / HMR**: Added zero-dependency Server-Sent Events controller (`Cast\Support\LiveReload` and framework-level route `GET /_cast/live-reload`). Features instant CSS stylesheet hot-swapping (0ms reload) and in-place SPA view swaps (`Cast.load()`) when `.cast.php` templates change without requiring Node.js, npm, or external watchers. Supports Vite via `'hmr' => 'vite'` in `config/app.php` (`'sse'` is default). In production, the internal route silently returns `204 No Content` with zero CSRF requirements.
- **Multi-App Session Isolation**: Dynamic session naming `<app_name>_session_<hash>` prevents cross-app session contamination when running multiple Cast apps on localhost/XAMPP. Automatically scopes cookie paths to `Config::get('app.base_path')` (e.g. `/app1/` vs `/app2/`).
- **Browser Error Auto-Clearing & Fallbacks**: Development error overlays and red badge pills now automatically purge resolved errors upon successful page navigation / HTTP 200. Added keyboard shortcuts (`Escape` to close overlay, `Ctrl + Shift + X` to clear all remembered errors), a visible `×` dismiss button directly on the floating error pill, and programmatic `Cast.clearErrors()` / `CastErrorOverlay.clear()`.
- **Modern Thin Scrollbars**: Implemented cross-browser 6px pill scrollbars (`scrollbar-width: thin` with WebKit fallbacks) matching theme tokens across demo views, `.page`, `<pre>` code terminals, tables, and dialogs. Added `scrollbar-gutter: stable` to eliminate layout shift.

## 2.1.0

Minor release integrating **CastTemplateEngine 1.5.0**, bringing native reactive client scripts, component-scoped CSS with dynamic variables, component asset bundling, and engine CLI tooling.

- **Reactive Client Scripts**: Component views can declare client interactivity using `@script(...)` and `@js(...)`. Scripts are captured and emitted once per request before `</body>` with `@scripts`. Includes zero-dependency client runtime with delegated event listener `handleEvent(event, selector, handler)` and `Cast.on(...)`, in-place DOM updates (`Cast.update`), and fragment swapping (`Cast.refresh`).
- **Scoped CSS & Dynamic CSS Variables**: Components can isolate styles with `@css(...)`. Selectors are automatically scoped under deterministic component classes (`.cast-<id>`), while dynamic PHP expressions (`@{ ... }`) are converted to CSS custom properties (`var(--cast-<id>-<n>)`) passed as inline styles. Styles are rendered in `<head>` via `@styles`.
- **Component Asset Auto-discovery & Bundler**: Dedicated component assets in `resources/css/components/` and `resources/js/components/` are automatically discovered when components render. The zero-dependency minifier and bundler (`php cast engine:build`) bundles them between marker comments in `resources/css/app.css` and `resources/js/app.js`.
- **CLI Engine Commands**: Direct integration of `php cast engine:*` commands into the framework CLI: `engine:build`, `engine:check`, `engine:compile`, `engine:clear`, `engine:snippets`, and `engine:editor`.
- **Deploy Optimization**: `php cast deploy:optimize` now automatically invokes component asset bundling.
- **Collection Shorthands**: Declarative JSX-style collection mapping (`@map`), collection filtering (`@filter`), chained operations (`@$items.filter(...).map(...)`), and automatic `data-el-key` attributes with diagnostic warnings.
- **VS Code Extension & Snippets**: Updated editor extension to v1.5.0 with hover docs for client runtime methods (`handleEvent`, `Cast.on`, `Cast.update`, `Cast.refresh`), directives syntax highlighting, and updated snippet triggers.

## 2.0.7

The next alpha after `2.0.0-alpha`: everything below, plus `static:check`, the dev-server warning bar, the shell-free `cast serve`, and `cast serve` noticing a busy port (it offers the next free one) and stopping the server together with the command (on Windows, Ctrl+C in Git Bash could leave it running; an old one an old server kept answering while the new one got no requests).

- `static:check` recognises Apache's folder listing (no root `.htaccess` reached) and the web server's own 404, instead of blaming PHP's router script.
- Apache / XAMPP without `/public` in the address: `cast init` writes a root `.htaccess` that sends requests to `public/`, and an empty `APP_BASE_PATH` on a real web server is now worked out from the address (`Application::detectBasePath`), so `http://localhost/my-app/` just works.

- `cast serve` now starts PHP without a shell (argument array via `proc_open`), so Windows `cmd.exe`/Git Bash quoting can no longer drop the router script (`public/index.php`); it prints the router it uses and refuses to start when it is missing.

- **A page with no styles, no dock and an empty `/cdocs` now explains itself.** When PHP's built-in server is started without the router script (`php -S 127.0.0.1:8000 -t public`), every `.css`, `.js` and `.svg` URL gets PHP's own 404 while pages still work. In development an inline check on each page asks for one of the framework's files and, if it does not come back, shows a red bar with the fix (`php cast serve`, or `php -S ... public/index.php`; on Apache/nginx the rewrite rules). `static:check` recognises PHP's own 404 and a `--url` that is a file instead of the app's address.
- **`php cast static:check [--url=URL]`**: finds why CSS, JS or the documentation do not show on a real install. In-process it checks the static folders and whether each file would be served; with `--url` it loads your page and requests every linked stylesheet and script, `/cdocs/` and its data on the real web server, and explains a wrong `APP_BASE_PATH`, a rewrite that returns the home page for a file, a 404, or a cut answer.
  The next major version, in alpha while more changes land. It carries everything below that was written after 1.1.0 (error handler 1.3 support, errors for SPA and API clients, the dock's error count):

### The dock counts development errors

- **Errors stay one click away after the overlay is closed.** The development error overlay remembers the errors of the tab (last 25, one entry per error with a count). The floating dock shows a red count on its button, lists them in its menu (click to reopen) and can clear them; without a dock (a front end of your own) a small "N errors - open" button appears in the corner. `CastErrorOverlay.errors()` / `.clear()` and the `cast:dev-errors` event for code.

### Development errors for SPA pages and front-end frameworks

- **Development errors reach SPA pages and front-end frameworks.** A server error (an exception, a PHP warning, a fatal error, an error in a view) used to give a Cast or API client only a message. In development (`APP_DEBUG=true`, not production) the JSON now has `data.debug`: the file and line, the code around it, which part failed, the stack, an _Open in editor_ link and `url`, the full error page (`GET /cast/error/<id>`, the last 25 are kept in `storage/framework/errors/`). The Cast client shows it as an **overlay** (`/cast/error-overlay.js`, loaded only when needed; a shadow root; Esc closes); a React / Vue / Next.js front end can load the same script or read `data.debug`. In production nothing of this is sent. Full page and editor links need `anode/error-handler` 1.3.
- The demo has a `/_crash` route to see it. Docs: the _Error handler_ page has screenshots of the error page and the overlay. `docs:build` copies `docs/images/` and the viewer shows `![alt](images/x.png)` images.

### Error handler 1.3 support

- **Error handler 1.3.0 support**: the development error page shows the failing code underlined in red with an _Open in editor_ link and the code of every stack step, and logs are one readable file per day with request, code and stack. The framework passes `root_path` and `editor` (`CAST_EDITOR`), `config/error-handler.php` (`make:config error-handler`) lists the new options (`editor`, `editor_path_map`, `snippet_lines`, `log_style`, `log_format`, `log_code_lines`), and `env:check` advises error-handler 1.3.0. Update with `composer update anode/error-handler`. Docs: the _Error handler_ page.

## 1.1.0

- **VS Code extension 0.3.0** (`php cast editor:install`): colours the template engine's `@` syntax (`@{ $x }`, `@foreach`, `@forelse`, `@if`, `@switch`, `@php` ...), and indents a whole view: Format Document, Format Selection and _Cast: Fix indentation_ understand HTML tags, component tags, PHP's `if (...): ... endif` syntax, multi-line `<?php ?>` blocks and the `@` directives together, and the indent stays right while you type (`cast.autoIndent`). Snippets for the `@` directives.
- Test fix: `init` test no longer shares a fixed folder in `/tmp`.

## 1.0.1

- Docs: the template engine's new `@` shorthand for PHP (`@{ $x }`, `@foreach ... @endforeach`, `@forelse`, `@if`, ...; engine 1.1.0).
- `Application::VERSION` (what `php cast version` and the welcome page show) is 1.0.1. The `v1.0.0` tag was made on the commit whose constant still said `0.12.0`; use 1.0.1 or newer.

## 1.0.0

The first stable release: everything in 0.1 to 0.12 (below), with the public API frozen as the README documents it. The last 0.x changes, which are the ones to know when moving from 0.11:

- **Plans (tiers) moved out of the framework**: they are app lifecycle, so they now live in the app (a ready-made package for a cast-app: `PlanService`, `PlanServiceProvider`, `php cast plan`, `plan_allows()`). Removed from the framework: `modules.tiers` / `tier` / `upgrade_url`, `modules:tier`, `TierStore`, `plan_allows()`, `module_tier()`, `make:module --tier` and the plan row of the database store.
- **`Modules::resolveUsing()`**: the one hook the app needs. A rule gets each module and returns `null` or a status (`state`, `reason`, `http`, `fault`, `message`, `headline`, `detail`, `action`) that replaces the framework's checks; extra keys in a module's config arrive as `$m['options']` (`Modules::options($name)`). A `fault => false` status is not reported by `modules:check` / `deploy:check`. The module gate and the fallback page use the status code and texts.
- The database store (`modules:table`) keeps only the per-module switches.

## 0.10.0

- **Module gating** (opt in, off by default: `CAST_MODULES=true`). List modules as core or optional in `config/modules.php` and put a group of routes behind a gate with `Router::module('reports', fn() => ...)` (a `ModuleGate` middleware). A module that is inactive, unbuilt (a `requires` class or file is missing) or unlisted answers `503` with a "module inactive or unavailable" page (`errors/module`, overridable; JSON envelope for APIs and the SPA client) instead of breaking the app. `module_active('name')` hides menu items.
- **Commands**: `make:module <Name> [--core] [--model]` (controller, views, `routes/modules/<name>.php`, config entry), `modules:list`, `modules:check` (fails when a core module is not active), `modules:enable|disable <name>` (core modules cannot be switched off). `deploy:check` includes the modules. `routes/modules/*.php` is loaded automatically. Switches are kept in `storage/framework/modules.json`, or bind your own `Cast\Contracts\ModuleStore` (`module_store`) for a database.

## 0.9.1

- **Leftover debugging is checked before you deploy.** `php cast deploy:scan` (and `deploy:check`) read your PHP, views and JavaScript for `dd()`, `dump()`, `var_dump()`, `print_r()`, `console.log()`, `debugger` and friends. `console.error()` is allowed. Opt out: `cast:keep` in a comment keeps one line, `--allow-debug` / `DEPLOY_ALLOW_DEBUG=true` skips the scan when you debug in production on purpose (`deploy:init` asks).

## 0.9.0

- **`demo:strip --pack=clean`**: removes everything, including the welcome UI and the components, and leaves a blank white page with one heading: the framework name, its version and how the page is made (route, controller, views).
- **Docs viewer**: thin scrollbars that follow the dark and light theme.
- **Password hashing** with `Cast\Support\Hash`: Argon2id when PHP has it, bcrypt otherwise (`config/hashing.php`, `HASH_DRIVER`; `make:config hashing`). Old bcrypt hashes still verify and are upgraded at login when the user provider has a `rehash()` method (the demo's does). `hashPassword()`, `verifyPassword()` and `Auth::hash()` use it. `encrypt()` / `decrypt()` / `sign()` / `unsign()` keep using `APP_KEY` (AES-256-GCM, HMAC-SHA256); the docs now say when to hash and when to encrypt.
- **`php cast make:service <Name>`** with working examples: `--example=printer` (ESC/POS thermal printer over the network or USB), `barcode` (Code 128 as SVG, no library), `qrcode` (through `chillerlan/php-qrcode`), or a blank service.
- **Deployment**: `deploy:init` asks the production questions (public address, cookie domain, SameSite, secure cookies, database login, CORS) and writes `.env.production` (mode 600, new `APP_KEY`, debug off); `deploy:check` audits settings, cookies, CORS, folders, the database and PHP; `deploy:optimize` clears compiled views and lists the speed-ups.
- **`php cast requirements [--production] [--json]`** checks the PHP version, extensions, limits and, for production, `display_errors`, OPcache and more. `composer.json` now requires `ext-openssl` and `ext-ctype`.

## 0.8.0

- **The framework's docs are at `/cdocs`** (was `/docs`), so `/docs` stays free for your own app. Config: `config/cdocs.php` (`make:config cdocs`), `cdocs.enabled` for production. If you copied the old `docs` entry into `config/static.php`, rename it to `cdocs`.
- **What's new**: the documentation has a _What's new_ page built from this changelog (`_meta.json` can add any file as a page with `"files"`), linked in the top bar.
- **`php cast demo:strip`** removes the demo from an app made with `init --demo` and leaves a starter pack: `shell`, `crud`, `auth` or `auth-crud` (asked, or `--pack=`; `--dry-run`, `--yes`). It rewrites the routes, provider, seeder and config, and writes an Account page for the `auth` pack.
- **The dock**: a floating button (bottom right) with links to the demo and the docs, added by the framework to HTML pages in development, so it is there even if you delete your layout and views. `CAST_DOCK=false` or `config/dock.php` turns it off; the menu has _Hide_. Never in production, Cast or JSON answers.
- **Demo menu**: the demo's pages sit in a group on the right: a grayed _Demo App_ label, Items, Stats and a _Logout_ button (the email is its tooltip). New settings in `config/app.php`: `auth`, `showcase` and `menu` (`[label, path, signed-in only]`), which `demo:strip` writes.

## 0.7.0

- **A welcome UI**: `php cast init` now starts on a designed welcome page ("Let's build something amazing"): the framework logo, a menu with _Docs_ and _Demo the Cast Framework_, a quick-start terminal, what you get, and your first five minutes. Dark and light themes (CSS variables in `app.css`), the fixed footer and a `100dvh` body.
- **`php cast init --demo` keeps that page** and adds Log in / Register at the top right. New: a **register** page (validates, creates the user, logs in), and a banner above login and register ("Use the framework for CRUD operations") with links to the docs (a `<CrudBanner />` component). `/demo` in the minimal app explains how to install the demo; in the demo it goes to the login.
- **The documentation at `/docs`** (moved to `/cdocs` in 0.8.0): a static viewer (`Resources/docs/index.html`: sidebar, search, on-this-page, copy buttons, dark/light) fed by `data.js`, made from the markdown by **`php cast docs:build`** (front matter: title, section, order, description; `docs/_meta.json`; a README is split into pages; links are rewritten; `--check` for CI). `composer docs` rebuilds the framework's own. Served in development. Guide: [docs/WRITING-DOCS.md](docs/WRITING-DOCS.md).
- **Colourful console**: `php cast list` is grouped (General, Db, Make, Migrate, ...), help has coloured headings, flags and examples, tables have coloured headers, `init` colours what it created. `NO_COLOR` and `FORCE_COLOR` are honoured; pipes stay plain.
- **Files that explain themselves**: the `cast` launcher (how to create a command, what not to change), `public/index.php`, `bootstrap/app.php`, `.htaccess`, `config/app.php`, routes, controllers, the demo's provider, services, models, migrations, seeders, views and scripts all carry comments (what it does, the options, what to copy). `make:command` writes a class with `$arguments`, `$options`, `$examples` and a how-to. A test fails when one of them has no comments.
- `StaticResourceProvider`: a mapping can have an `index` file and be `dev_only`; `config/docs.php` (`make:config docs`).

## 0.6.0

- **`php cast make:config <name>`** writes `config/<name>.php` with every setting of a section and its default, documented (`app`, `database`, `session`, `cors`, `static`, `view`, `api`, `spa`, `auth`, `models`, `helpers`, `request`, `console`, `error-handler`; `--all`, `--list`). A test keeps each file equal to the framework's defaults. `config/error-handler.php` holds the error handler's options (`enabled => false` turns it off).
- **`APP_KEY`**: `php cast key:generate [--show] [--force]`; `config('app.key')`; `sign()` / `unsign()`, `encrypt()` / `decrypt()` (`Cast\Support\Crypt`, AES-256-GCM and HMAC-SHA256). `php cast init` generates one. `env:check` reports a missing key (a failure in production) and an old template engine or error handler.
- **`.env` and `.env.example`** written by `php cast init` list every setting, including the session cookie (`SESSION_NAME`, `COOKIE_LIFE`, `COOKIE_PATH`, `COOKIE_DOMAIN`, `COOKIE_SECURE`, `COOKIE_HTTP_ONLY`, `COOKIE_SITE`), `APP_KEY`, `APP_TIMEZONE`, CORS and the database. `.env.example` has no key.
- **Views, CSS and JS inside `src/`**: with the default source folder `src`, `php cast init` puts them in `src/resources/` (`bootstrap/app.php` sets the paths, the static file map follows `paths.resources`, `.vscode/settings.json` points the extension). `--resources=inside|root`.
- Errors in a view name the view and its line (needs `cast-template-engine` 1.0.3: the compiled cache file and the framework's frames are no longer what an error page shows). `htchars()` accepts `null` and numbers.
- The framework requires `cast-template-engine ^1.0.2` and `error-handler ^1.0.19`; UPGRADING explains updating them together.

## 0.5.2

- Fix: on a PHP fatal error the framework's JSON answer for Cast and API clients was followed by the Anode error handler's HTML page (the handler's shutdown function ran after ours), so the response was not valid JSON. The JSON answer now ends the request. For browsers the error handler, when it is on, keeps showing its own page.

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
