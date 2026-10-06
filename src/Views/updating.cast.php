<?php
/** Update-in-progress page. Variables: $message, $appName, $homeUrl, $from, $to. */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title><?= htchars($appName) ?> | Updating</title>
    <?php __includes('errors._style'); ?>
</head>

<body>
    <main class="card" role="main">
        <div class="code">503</div>
        <h1>Updating the system</h1>
        <p><?= htchars($message) ?></p>
        <?php if (!empty($from) && !empty($to)) : ?><p class="hint">Version <?= htchars((string) $from) ?> &rarr; <?= htchars((string) $to) ?></p><?php endif ?>
        <a class="btn" href="<?= htchars($homeUrl) ?>">Try again</a>
    </main>
</body>

</html>
