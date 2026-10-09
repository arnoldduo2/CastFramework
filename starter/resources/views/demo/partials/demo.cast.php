<?php
/** "Demo the Cast Framework" (route /demo, HomeController::demo). In the demo app this route sends you to the demo instead (see routes/web.php). */
$docs = config('app.env') !== 'production' || config('docs.enabled');
?>
<section class="hero">
    <span class="chip dot">Live demo</span>
    <h1>See a <em>real app</em> built on it.</h1>
    <p class="lead">The demo adds a login and a register page, create/read/update/delete on a table of items with an edit popup, a stats page, and a JSON API with tokens. Every file is commented so you can copy from it.</p>
</section>

<div class="terminal">
    <div class="bar"><span class="dots"><i></i><i></i><i></i></span><span>install the demo</span><button type="button" data-copy="php cast init --demo --force">Copy</button></div>
    <pre><span class="p">$</span> php cast init <span class="f">--demo --force</span>      <span class="c"># adds the demo files, migrates and seeds a SQLite database</span>
<span class="p">$</span> php cast serve                 <span class="c"># then reload this page: the menu gets Log in and Register</span></pre>
</div>
<p class="muted">Demo login: <code>admin@example.com</code> / <code>password</code>, or register your own account. <code>--force</code> replaces the starter files (routes, config, layouts, this welcome page); your <code>.env</code> is never touched. Starting a new project instead? Run <code>php cast init</code> in an empty folder.</p>

<div class="cta">
    <a class="btn" href="<?= route('/') ?>">Back to the welcome page</a>
    <?php if ($docs) : ?><a class="btn btn-ghost" href="<?= route('/docs/') ?>" data-cast="off">Read the docs</a><?php endif ?>
</div>
