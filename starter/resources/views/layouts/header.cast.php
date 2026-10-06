<?php
/** @var array $data parentName, pageName, authguard, plus the page's own data */
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
$user = __getUser();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htchars(\Cast\Core\Session::csrfToken()) ?>">
    <meta name="base-url" content="<?= htchars(route("/")) ?>">
    <title><?= htchars($appName) ?> | <?= htchars(__ucwords($pageName)) ?></title>
    <?= __modules('app', 'css') ?>
    <?= __modules("$parentName.$pageName", 'css') ?>
</head>

<body>
    <?= __getAlerts() ?>
    <header class="topbar">
        <a class="brand" href="<?= route('/') ?>"><?= htchars($appName) ?></a>
        <nav>
            <a href="<?= route('/') ?>">Home</a>
            <?php if ($user) : ?>
                <a href="<?= route('/items') ?>">Items</a>
                <form method="post" action="<?= route('/logout') ?>" class="inline">
                    <?= __csrf() ?>
                    <button type="submit" class="link"><?= htchars($user['email']) ?> (log out)</button>
                </form>
            <?php else : ?>
                <a href="<?= route('/login') ?>">Log in</a>
            <?php endif ?>
        </nav>
    </header>
    <main class="page <?= htchars($pageName) ?>">
