<?php
/**
 * The top of every page. Each page view opens with  __includes('layouts.header', $data)  and ends with  __includes('layouts.footer', $data);
 * together they wrap the page's content. $data is the page's data: parentName and pageName (they choose the page's own css and js),
 * authguard, title ...
 *
 * This is the framework's welcome menu: replace the markup between <header> and </header> with your own. The <head> part
 * is what every page needs: __cast() is the SPA client (remove that line if you do not use it), __modules() loads the css and js
 * that belong to the page by name, and the csrf-token meta is read by the client for forms and requests.
 */
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
$demo = (bool) config('app.demo');                       // true when the app was made with  php cast init --demo
$user = $demo ? __getUser() : null;                      // the logged-in user (the demo has a login)
$docs = config('app.env') !== 'production' || config('cdocs.enabled');   // the documentation viewer at /cdocs
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htchars(\Cast\Core\Session::csrfToken()) ?>">
    <meta name="base-url" content="<?= htchars(route('/')) ?>">
    <title><?= htchars((string) config('app.name', 'App')) ?> | <?= htchars(__ucwords($pageName)) ?></title>
    <link rel="icon" href="<?= route('/cast/logo.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <?= __cast($data['authguard'] ?? '') ?>
    <?= __modules('app', 'css') ?>
    <?= __modules("$parentName.$pageName", 'css') ?>
</head>

<body>
    <?= __getAlerts() ?>
    <header class="nav">
        <a class="brand" href="<?= route('/') ?>"><img src="<?= route('/cast/logo.svg') ?>" alt="">CastFramework</a>
        <nav class="nav-links">
            <a href="<?= route('/') ?>">Home</a>
            <?php if ($demo && $user) : ?>
                <a href="<?= route('/items') ?>">Items</a>
                <a href="<?= route('/stats') ?>">Stats</a>
            <?php endif ?>
            <?php if ($docs) : ?><a href="<?= route('/cdocs/') ?>" data-cast="off">Docs</a><?php endif ?>
            <a class="nav-demo" href="<?= route('/demo') ?>">Demo the Cast Framework</a>
        </nav>
        <div class="nav-end">
            <?php if ($demo && $user) : ?>
                <form method="post" action="<?= route('/logout') ?>" class="inline" data-cast-form>
                    <?= __csrf() ?>
                    <button type="submit" class="link"><?= htchars($user['email']) ?> (log out)</button>
                </form>
            <?php elseif ($demo) : ?>
                <a href="<?= route('/login') ?>">Log in</a>
                <a class="btn" href="<?= route('/register') ?>">Register</a>
            <?php else : ?>
                <span class="chip">v<?= htchars(\Cast\App\Application::VERSION) ?></span>
                <a href="https://github.com/arnoldduo2/CastFramework" target="_blank" rel="noopener">GitHub</a>
            <?php endif ?>
        </div>
    </header>
    <main class="page <?= htchars($pageName) ?>">
