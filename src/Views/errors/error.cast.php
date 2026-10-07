<?php
/**
 * Generic error page. Variables: $code, $title, $message, $appName, $homeUrl.
 * Override it by creating `errors/error.cast.php` (or `errors/{code}.cast.php`) in your app's views folder.
 */
$hints = [
    403 => 'You do not have permission to open this page.',
    404 => 'The page may have moved, or the address may be wrong.',
    405 => 'This address does not accept that kind of request.',
    419 => 'Your session has expired. Reload the page and try again.',
    500 => 'Something went wrong on our side. Please try again shortly.',
    503 => 'The service is temporarily unavailable.',
];
$hint = $hints[$code] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htchars($appName) ?> | <?= (int) $code ?> <?= htchars($title) ?></title>
    <?php __includes('errors._style'); ?>
</head>

<body>
    <main class="card" role="main">
        <div class="code"><?= (int) $code ?></div>
        <h1><?= htchars($title) ?></h1>
        <?php if ($message !== $title) : ?><p><?= htchars($message) ?></p><?php endif ?>
        <?php if ($hint && $hint !== $message) : ?><p class="hint"><?= htchars($hint) ?></p><?php endif ?>
        <?php if (!empty($notice)) : ?><p class="hint" role="note"><strong>Development note:</strong> <?= htchars($notice) ?></p><?php endif ?>
        <a class="btn" href="<?= htchars($homeUrl) ?>">Back to <?= htchars($appName) ?></a>
    </main>
</body>

</html>
