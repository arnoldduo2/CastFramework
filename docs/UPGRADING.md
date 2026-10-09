---
title: Upgrading
section: Running it
order: 7
description: Moving an app to a newer version, adopting migrations, keeping the packages current.
---

# Upgrading

Run the commands in your app's folder (for XAMPP: `cd D:\xampp\htdocs\my-app`).

## 0.x to 0.3 (an app made with `cast init --demo` on 0.2.x)

**1. Move to the new version.** For versions below 1.0 Composer's `^0.2` means "0.2.x only", so a plain `composer update` will not leave 0.2.x. Ask for the new line:

```bash
composer require anode/cast-framework:^0.3
```

The app keeps working as it is (the framework is backward compatible). Composer may ask once whether to trust the plugin (`anode/cast-framework`): answer `y`
(or `composer config allow-plugins.anode/cast-framework true`). Since 0.2.3 you no longer need `composer dump-autoload` for the `App\` classes.

**2. (Optional) take the new starter files.** The starter changed since 0.2: migrations and a seeder instead of tables created at boot, the edit modal, the stats page, the API routes,
a better layout. To refresh your copy of the demo (your own edits to those files are overwritten, `.env` and your data are not):

```bash
php cast init --demo --force --no-migrate
```

**3. Adopt the migrations without losing data.** The tables already exist, so running the migrations would fail with `table "users" already exists`. Record them as done instead:

```bash
php cast migrate:baseline      # marks the pending migrations as run, executes nothing
php cast migrate:status        # all "Yes"
```

From now on `php cast make:migration ...` and `php cast migrate` work normally. (Starting over instead? `php cast migrate:fresh --seed` drops every table and rebuilds the demo.)

**4. Editor support.** `php cast editor:install`, then reload the VS Code window.

**5. Check.** `php cast list` shows `migrate`, `editor:install`, `token:*`; open the app and log in.

## Not on the demo?

An app of your own that never used `init --demo`: step 1 is all that is needed. To start using migrations for tables you already have, write one migration per table
(`php cast make:migration create_orders_table`), then `php cast migrate:baseline` so they are recorded without being run.

## Versions

See [CHANGELOG.md](CHANGELOG.md) for what each release changed.

## Legacy tables: `migrate:sync`

From 0.4.0, tables that were never created by a migration can be turned into migrations: run `php cast migrate:sync --init` to test the relationships, then `php cast migrate:sync`.
The files are recorded as run, so nothing is executed against your data. See [Legacy databases](../README.md#legacy-databases-migratesync).

## The default source folder is now `src`

From 0.5.0 `php cast init` creates new apps with their classes in `src/` (it asks). An app made earlier keeps working untouched: its `config/app.php` says `'source_path' => 'app'`. If an old app has no `source_path` line the framework still assumes `app/`.
To move an old app to `src/`: move the folder, set `'source_path' => 'src'` in `config/app.php` and `"App\\": "src/"` in `composer.json`, then `composer dump-autoload`.

## Keeping the two packages current

`anode/cast-template-engine` and `anode/error-handler` are separate packages: `composer update anode/cast-framework` alone leaves them where `composer.lock` has them. Update them together:

```
composer update anode/cast-framework anode/cast-template-engine anode/error-handler --with-all-dependencies
```

`php cast env:check` tells you when either is older than the framework expects. (The framework requires at least `cast-template-engine ^1.0.2` and `error-handler ^1.0.19`.)

## Views, CSS and JS inside `src/` (0.6.0)

New apps made with the default `src` source folder keep `views/`, `css/` and `js/` in `src/resources/`. Existing apps are unchanged. To move one: move `resources/` into `src/`, add `['paths' => ['views' => 'src/resources/views', 'resources' => 'src/resources']]` as the second argument of `new Application(...)` in `bootstrap/app.php`, and (for the editor) set `cast.viewsPath`, `cast.componentsPath` and `cast.resourcesPath` in `.vscode/settings.json`.
