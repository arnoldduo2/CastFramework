---
title: Error handler
section: Running it
order: 3
description: Anode Error Handler, installed with the framework - logging, the developer error page, and how to configure or turn it off.
---

# Error handler

**Anode Error Handler** (`anode/error-handler`) is installed with the framework. On web requests `Cast\Services\ErrorProvider` creates it with
options taken from your config, and it then catches every PHP error, uncaught exception and fatal error, so a mistake becomes a readable page and a
log line instead of a blank screen. Its own README is at <https://github.com/arnoldduo2/error-handler>.

What it does:

- **Development** (`APP_ENV=development`): a detailed page with the message, file, line and stack trace.
- **Production**: a plain "something went wrong" page for visitors and the details in the log. Never set `APP_DEBUG=true` in production.
- **Logging**: one file per day in `storage/logs/`; optional separate developer logs and error e-mails.
- **Ajax, Cast and API requests** get JSON, not HTML. The framework also answers HTTP errors itself (404, 403, 405, 419, 503) with its own pages:
  these are not PHP errors, so the handler is not involved.

## Configure it

`php cast init` asks whether to use it (and shows every setting with its default). Later:

```bash
php cast make:config error-handler        # writes config/error-handler.php with every option and its default
```

```php
// config/error-handler.php (the file wins over config/app.php 'error_handler' => [...])
return [
    'enabled' => true,                     // false: do not register it at all
    'log_errors' => true,
    'log_directory' => 'storage/logs/',    // folders are relative to the app
    'dev_logs' => false,                   // extra, more detailed logs
    'dev_logs_directory' => 'storage/logs/dev/',
    'display_errors' => false,             // leave off: the handler shows its own page
    'error_reporting_level' => E_ALL,
    'email_logging' => false,              // e-mail errors; needs an object with a send() method
    'email_logging_address' => '',
    'email_logging_subject' => 'Error Log',
    'error_view' => null,                  // your own 500 page (a PHP file); null = the handler's
];
```

The framework fills in `app_name`, `app_debug`, `app_enviroment` (sic, the package's spelling), `base_url` and `log_directory` from your `.env`
(`APP_NAME`, `APP_DEBUG`, `APP_ENV`, `APP_BASE_PATH`); you only list what differs. `'error_handler' => false` in `config/app.php` turns it off.
With it off, PHP's own handling applies: use it only if another tool (Sentry, Whoops) takes over.

## Error pages of your own

HTTP error pages are views: create `resources/views/errors/404.cast.php` (or `403`, `405`, `419`, `500`, `503`, or `error.cast.php` for all) and
yours replaces the framework's; an **empty** file means "not built yet", so the framework page shows and, in development, says which view you still
have to write. `php cast init` can create the empty ones (`--error-pages=custom`). The page the handler shows for a 500 in production is
`error_view`.

## In practice

- A fatal error in a Cast or API request ends with the JSON envelope `{status: "error", msg, data}` and exit code 1; browsers get the handler's page.
- Logs are plain text: `tail -f storage/logs/<date>.log` while you work; `php cast deploy:check` checks that `storage/` is writable and debug is off.
- `php cast env:check` warns when the installed handler is older than the version the framework was tested with (1.0.19).
- Check the version with `composer show anode/error-handler`; update with `composer update anode/error-handler`.
