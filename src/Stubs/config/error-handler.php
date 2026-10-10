<?php

// The Anode error handler: logs errors and shows a developer page (development) or a friendly page (production).
// Every value shown is the package's default; delete what you do not change. app_name, app_enviroment, app_debug and base_url
// come from your .env (APP_NAME, APP_ENV, APP_DEBUG, APP_BASE_PATH); you can override them here too.
// Folders are relative to the app.
return [
    'enabled' => true,                             // false turns the handler off (PHP's own error output is used)
    'log_errors' => true,                          // write every error to a log file
    'log_directory' => 'storage/logs/',
    'dev_logs' => false,                           // extra developer logs with more detail
    'dev_logs_directory' => 'storage/logs/dev/',
    'display_errors' => false,                     // let PHP print errors into the page (leave off: the handler shows its own page)
    'error_reporting_level' => E_ALL,
    'email_logging' => false,                      // email errors (needs a mailer object)
    'email_logging_address' => '',
    'email_logging_subject' => 'Error Log',
    'email_logging_mailer' => null,
    'email_logging_mailer_options' => [],
    'log_style' => 'daily',                        // daily: one errors-DATE.log with every error of the day; per_error: a file for each error
    'log_format' => 'text',                        // text (readable) or json (one object per line, errors-DATE.jsonl)
    'log_code_lines' => 3,                         // lines of code kept above and below the failing line in a log entry
    'editor' => 'vscode',                          // the "Open in editor" links on the error page: vscode, cursor, phpstorm, sublime, none (env CAST_EDITOR)
    'editor_path_map' => [],                       // code on Docker, WSL or a VM: ['/var/www/html' => 'C:/xampp/htdocs/app']
    'snippet_lines' => 6,                          // lines of code shown around the failing line on the error page
    'error_view' => null,                          // path of a PHP file shown to visitors in production (null = the package's page)
];
