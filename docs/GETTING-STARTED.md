---
title: Getting started
section: Start
order: 0
description: Install, the workflow of a page, where every file goes, wiring a feature, and turning the demo into your own project.
---

# Getting started

How a CastFramework app is wired, where every kind of file goes, and how to turn the demo into your own project.

## 1. Install and run

```bash
mkdir my-app && cd my-app
composer init --name=me/my-app --no-interaction
composer require anode/cast-framework
php cast init              # asks a few questions, then creates the app   (php cast init --demo : the full demo)
php cast serve             # http://127.0.0.1:8000
```

`php cast init` asks (press Enter for the [default] each time; `-n` asks nothing and uses the defaults):

| Question | Default | What it changes |
| --- | --- | --- |
| Folder for your app source code | `src` | where controllers, models, services live (`--source=app` for the classic layout); the `App\` namespace is mapped to it in `composer.json` and `config/app.php` (`source_path`) |
| How will the front end work? | the built-in SPA | **spa**: server pages + the Cast client (no full reloads); **php**: server pages, normal page loads, no client script; **external**: an API for a React/Vue/Next.js front end (asks its address for CORS); **api**: JSON only. (`--frontend=spa\|php\|external\|api`) |
| Use the Anode error handler? | yes | logs errors and shows a developer page. Answer yes to see every setting with its default, then choose to configure it (log folder, developer logs, `display_errors`, email) or keep the defaults. (`--error-handler=yes\|no`) |
| Error pages | the framework's | **custom** creates empty views in `resources/views/errors` for you to build; until you do, the framework's page is shown, with a note in development. (`--error-pages=default\|custom`) |

| Where do the views, CSS and JS go? | inside the source folder when it is `src` | `inside`: `src/resources/views`, `src/resources/css`, `src/resources/js` (everything of the app in `src/`); `root`: `resources/` next to it. (`--resources=inside\|root`) The paths are set in `bootstrap/app.php`, and `.vscode/settings.json` tells the editor extension |

All answers end up in `config/app.php` (and `.env` for the CORS origin) and can be edited later.

Then, once: `php cast editor:install` (VS Code highlighting, Ctrl+click on components, prop hints) and `php cast ide:helpers` (so the editor knows `htchars()`, `views()` and the other helpers).
`php cast list` shows every command; `php cast help <command>` shows its arguments and flags ([COMMANDS.md](COMMANDS.md)).

> **Paths in this guide.** With the default layout the views, CSS and JS are in `src/resources/` (so `resources/views/...` below means `src/resources/views/...`). With `--resources=root` they are in `resources/` at the root.

## 2. The workflow: one path for every page

```
browser ──► public/index.php ──► routes ──► controller ──► view ──► response
                                   │            │           │
                          routes/web.php   src/Controllers  resources/views
                          routes/api.php   + src/Models     + css/js loaded by name
```

1. **Route** (`routes/web.php`): `Router::get('/orders', [OrdersController::class, 'index']);` Every verb exists (`get post put patch delete`), plus `group`, `middleware`, `{param}`.
2. **Controller** (`src/Controllers/OrdersController.php`, `php cast make:controller Orders`): validate with `$this->validate([...])`, use a model, return `$this->view('orders.orders', $data)` or `$this->success(...)`.
3. **View** (`resources/views/orders/orders.cast.php`): includes the layout header, the page's partial, the layout footer. Pieces you reuse are **components**, used as tags: `<Btns.Button label="Save" />`.
4. **CSS and JS load by name.** A page with `parentName = 'orders'` and `pageName = 'orders'` gets `resources/css/orders/orders.css` and `resources/js/orders/orders.module.js` when those files exist. `resources/css/app.css` and `resources/js/app/app.module.js` load on every page. You never write `<link>` or `<script>` for them.
5. **SPA or normal page:** add `'spa' => true` to the page data and the first visit loads the shell, then links swap only the content. The controller is the same either way ([SPA in the README](../README.md#spa-pages-without-full-reloads)).
6. **Data:** models in `src/Models`, tables as migrations in `database/migrations` (`php cast make:migration create_orders_table`, `php cast migrate`). Demo rows go in `database/seeders`.
7. **JSON API:** `routes/api.php` is served under `/api`, always answers JSON, and accepts bearer tokens (`php cast token:create <login>`).

## 3. Where everything goes

| I want to... | File or folder |
| --- | --- |
| add a URL | `routes/web.php` (pages), `routes/api.php` (JSON) |
| handle that URL | `src/Controllers/` |
| change a page's HTML | `resources/views/<page>/partials/<page>.cast.php` |
| change the header, menu, footer | `resources/views/layouts/header.cast.php`, `footer.cast.php` |
| make a reusable piece | `resources/views/components/` (`php cast make:component Btns.AddNew`) |
| make a popup form | `resources/views/<page>/modals/` |
| style the app / one page | `resources/css/app.css` / `resources/css/<page>/<page>.css` |
| script the app / one page | `resources/js/app/app.module.js` / `resources/js/<page>/<page>.module.js` |
| talk to the database | `src/Models/`, `database/migrations/`, `database/seeders/` |
| add a rule to forms | `php cast make:rule`, or string rules in `$this->validate()` |
| write business logic | `src/Services/` |
| add a helper function | a `*.php` file in `src/helpers/` (listed in `config/helpers.php`) |
| change settings | `config/*.php`; secrets and per-machine values in `.env` |
| change the error pages | `resources/views/errors/` (`404.cast.php`...; an empty file means "not built yet": the framework's page is used) |
| add a console command | `php cast make:command`, then list it in `config/console.php` |

## 4. Wiring a new feature, end to end

```bash
php cast make:migration create_orders_table      # edit it: columns
php cast migrate
php cast make:model Orders
php cast make:controller Orders
php cast make:component Btns.AddNew --props=label:string=Add
```

1. `routes/web.php`: `Router::get('/orders', OrdersController::class);`
2. `OrdersController::index()`: `return $this->view('orders.orders', ['parentName' => 'orders', 'pageName' => 'orders', 'authguard' => 'private', 'spa' => true, 'orders' => Orders::getAll()]);`
3. `resources/views/orders/orders.cast.php`: the three lines `__includes('layouts.header', $data); __includes('orders.partials.orders', $data); __includes('layouts.footer', $data);`
4. `resources/views/orders/partials/orders.cast.php`: your HTML and components.
5. Optional: `resources/css/orders/orders.css`, `resources/js/orders/orders.module.js` (they load by themselves).

## 5. Turn the demo into your own project

If you started with `php cast init --demo`, delete what belongs to the demo and keep the structure:

| Delete | Keep |
| --- | --- |
| `resources/views/items`, `stats` (and `auth` if you do not want the login) | `layouts/`, `components/`, `home/`, `errors/` |
| `resources/css/items`, `stats`, `resources/js/items`, `stats` | `resources/css/app.css`, `resources/js/app/app.module.js` |
| `src/Controllers/ItemsController.php`, `StatsController.php`, `Api/` | `HomeController.php` (and `AuthController.php` with the login) |
| `src/Models/Items.php` | `Users.php` if you keep the login |
| the demo routes in `routes/web.php` and `routes/api.php` | the home route |
| `database/migrations/…create_items_table.php` | `users`, `api_tokens` if you keep the login / API |

Then `php cast migrate:fresh --seed` rebuilds the database without the demo tables, and you edit `resources/views/home/partials/home.cast.php` to replace the welcome page.
To start with nothing in a new folder, run `php cast init` without `--demo`.

## 6. Already have a database?

`php cast migrate:sync --init` tests the relationships; `php cast migrate:sync` writes migrations for the tables you have ([Legacy databases](../README.md#legacy-databases-migratesync)).
Upgrading an app made on an older version: [UPGRADING.md](UPGRADING.md).

## 6b. Settings

`php cast init` writes `.env` (with its own `APP_KEY`) and `.env.example` listing every setting: app, session cookie, CORS, database. `php cast make:config <name>` (`--list`) writes a config file for a section with all its defaults, e.g. `make:config session` or `make:config error-handler`.
`php cast key:generate` makes or rotates the app key; `sign()` / `unsign()` and `encrypt()` / `decrypt()` use it.

## 7. When something looks wrong

| Symptom | Do |
| --- | --- |
| "Undefined function 'htchars'" in the editor (the app runs) | restart the editor window, or `php cast ide:helpers` |
| an API-only app (`--frontend=external` or `api`) has no views | `routes/api.php` and `src/Controllers/`; it answers JSON under `/api` |
| an error page names a file in `storage/framework/views/` or in `vendor/` | update the engine: `composer update anode/cast-template-engine` (1.0.3 names your view and its line); `php cast env:check` says whether it is current |
| a page loads forever / a 500 with no detail | set `APP_DEBUG=true` in `.env`; the message names the template. `php cast views:check` finds views that start with `declare(strict_types=1);` |
| old compiled views | `php cast views:clear` |
| the environment looks off | `php cast env:check` |
| nothing happens after `composer update` | on 0.x, `^0.3` does not reach 0.4: `composer require anode/cast-framework:^0.4` |
