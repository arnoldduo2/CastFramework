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
