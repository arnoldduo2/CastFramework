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
    'error_view' => null,                          // path of a PHP file shown to visitors in production (null = the package's page)
];
