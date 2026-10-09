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
$hasAuth = (bool) config('app.auth', $demo);             // the app has a login (the demo, or a pack made with  php cast demo:strip)
$showcase = (bool) config('app.showcase', true);         // the "Demo the Cast Framework" link (php cast demo:strip removes it)
// config('app.menu') is a list of [label, path, signed-in-only] shown after Home, e.g. ['Items', '/items', true]
$user = $hasAuth ? __getUser() : null;                   // the logged-in user
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
            <?php foreach ((array) config('app.menu', []) as [$label, $path, $private]) : ?>
                <?php if (!$private || $user) : ?><a href="<?= route($path) ?>"><?= htchars($label) ?></a><?php endif ?>
            <?php endforeach ?>
            <?php if ($docs) : ?><a href="<?= route('/cdocs/') ?>" data-cast="off">Docs</a><?php endif ?>
            <?php if ($showcase) : ?><a class="nav-demo" href="<?= route('/demo') ?>">Demo the Cast Framework</a><?php endif ?>
        </nav>
        <div class="nav-end">
            <?php if ($demo && $user) : ?>
                <!-- the demo's own menu: a grayed label, then its pages, then log out (the email is the button's tooltip) -->
                <nav class="demo-menu" aria-label="Demo app">
                    <span class="demo-label">Demo App</span>
                    <a href="<?= route('/items') ?>">Items</a>
                    <a href="<?= route('/stats') ?>">Stats</a>
                    <form method="post" action="<?= route('/logout') ?>" class="inline" data-cast-form>
                        <?= __csrf() ?>
                        <button type="submit" class="btn-out" title="<?= htchars($user['email']) ?>">Logout</button>
                    </form>
                </nav>
            <?php elseif ($hasAuth && $user) : ?>
                <form method="post" action="<?= route('/logout') ?>" class="inline" data-cast-form>
                    <?= __csrf() ?>
                    <button type="submit" class="btn-out" title="<?= htchars($user['email']) ?>">Logout</button>
                </form>
            <?php elseif ($hasAuth) : ?>
                <a href="<?= route('/login') ?>">Log in</a>
                <a class="btn" href="<?= route('/register') ?>">Register</a>
            <?php else : ?>
                <span class="chip">v<?= htchars(\Cast\App\Application::VERSION) ?></span>
                <a href="https://github.com/arnoldduo2/CastFramework" target="_blank" rel="noopener">GitHub</a>
            <?php endif ?>
        </div>
    </header>
    <main class="page <?= htchars($pageName) ?>">
