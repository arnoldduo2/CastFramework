<?php
/** Maintenance page. Variables: $message, $appName, $homeUrl, $status (see MaintenanceManager::status()). */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title><?= htchars($appName) ?> | Under maintenance</title>
    <?php __includes('errors._style'); ?>
</head>

<body>
    <main class="card" role="main">
        <div class="code">503</div>
        <h1>Under maintenance</h1>
        <p><?= htchars($message) ?></p>
        <p class="hint">This page refreshes automatically. Thank you for your patience.</p>
        <a class="btn" href="<?= htchars($homeUrl) ?>">Try again</a>
    </main>
</body>

</html>
