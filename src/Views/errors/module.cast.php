<?php
/**
 * The page for a module that is inactive, not built yet or not listed (module gating, config/modules.php).
 * Variables: $module (name, title, core, state: inactive|unbuilt|unregistered or one your own rule returns, reason, headline, detail, action, http), $message, $appName, $homeUrl.
 * Override it with errors/module.cast.php in your app's views folder.
 */
$state = $module['state'] ?? 'inactive';
$headline = $module['headline'] ?? ($state === 'inactive' ? 'Module inactive' : 'Module unavailable');
$detail = $module['detail'] ?? ($state === 'inactive'
    ? 'This part of ' . $appName . ' has been switched off. Ask an administrator to turn it on.'
    : 'This part of ' . $appName . ' is not installed or finished yet.');
$action = $module['action'] ?? null;      // ['label' => ..., 'url' => ...] added by an app rule
$debug = (bool) config('app.debug');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htchars($appName) ?> | <?= htchars($headline) ?></title>
    <?php __includes('errors._style'); ?>
</head>

<body>
    <main class="card" role="main">
        <div class="code"><?= (int) ($module['http'] ?? 503) ?></div>
        <h1><?= htchars($headline) ?></h1>
        <p><strong><?= htchars((string) ($module['title'] ?? 'This module')) ?></strong>: <?= htchars($detail) ?></p>
        <?php if ($debug && !empty($module['reason'])) : ?><p class="muted"><?= htchars($module['reason']) ?> (<?= !empty($module['core']) ? 'core' : 'optional' ?> module <code><?= htchars((string) ($module['name'] ?? '')) ?></code>)</p><?php endif ?>
        <?php if (is_array($action) && !empty($action['url'])) : ?><p><a href="<?= htchars((string) $action['url']) ?>"><?= htchars((string) ($action['label'] ?? 'Learn more')) ?></a></p><?php endif ?>
        <a class="btn" href="<?= htchars($homeUrl) ?>">Back to <?= htchars($appName) ?></a>
    </main>
</body>

</html>
