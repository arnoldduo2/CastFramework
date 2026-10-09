<?php
/**
 * The welcome page (route "/", HomeController::index). It is only content: the menu and footer come from layouts/header and
 * layouts/footer. Replace this file with your own home page; nothing else depends on it.
 */
$docs = config('app.env') !== 'production' || config('docs.enabled');
$demo = (bool) config('app.demo');
$quick = "php cast make:controller Orders\nphp cast make:migration create_orders_table\nphp cast migrate\nphp cast make:component Btns.AddNew --props=label:string=Add";
?>
<section class="hero">
    <span class="chip dot">CastFramework v<?= htchars(\Cast\App\Application::VERSION) ?> &middot; PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></span>
    <h1>Let's build something <em>amazing</em>.</h1>
    <p class="lead">Server-rendered pages that behave like a single-page app, a JSON API when you want one, and tools that explain themselves. Your app is running: start with the docs, or try the demo.</p>
    <div class="cta">
        <?php if ($docs) : ?><a class="btn" href="<?= route('/docs/') ?>" data-cast="off">Read the docs</a><?php endif ?>
        <a class="btn btn-secondary" href="<?= route('/demo') ?>">Demo the Cast Framework</a>
    </div>
</section>

<div class="terminal">
    <div class="bar"><span class="dots"><i></i><i></i><i></i></span><span>your first feature</span><button type="button" data-copy="<?= htchars($quick) ?>">Copy</button></div>
    <pre><span class="p">$</span> php cast make:controller Orders                 <span class="c"># app/Controllers/OrdersController.php</span>
<span class="p">$</span> php cast make:migration create_orders_table     <span class="c"># database/migrations/...</span>
<span class="p">$</span> php cast migrate
<span class="p">$</span> php cast make:component Btns.AddNew <span class="f">--props=</span>label:string=Add   <span class="c"># a documented component</span></pre>
</div>

<p class="section-title">What you get</p>
<div class="grid">
    <div class="feature"><span class="n">01</span><h3>Routing for every verb</h3><p>GET, POST, PUT, PATCH and DELETE, groups, middleware, one CSRF token checked in one place.</p></div>
    <div class="feature"><span class="n">02</span><h3>Pages without reloads</h3><p>Mark a page <code>'spa' =&gt; true</code>: links swap only the content. No build step, no framework to learn.</p></div>
    <div class="feature"><span class="n">03</span><h3>A JSON API</h3><p><code>routes/api.php</code> with bearer tokens, abilities, rate limits and always-JSON errors.</p></div>
    <div class="feature"><span class="n">04</span><h3>Migrations that read your database</h3><p>Write them, or turn a legacy database into migrations with <code>php cast migrate:sync</code>.</p></div>
    <div class="feature"><span class="n">05</span><h3>Components and an editor that knows them</h3><p>Tags like <code>&lt;Btns.Button /&gt;</code>, Ctrl+click to the file, props from docblocks.</p></div>
    <div class="feature"><span class="n">06</span><h3>A console that explains itself</h3><p><code>php cast list</code>, <code>php cast help &lt;command&gt;</code>, colours, and <code>make:*</code> for everything.</p></div>
</div>

<p class="section-title">Your first five minutes</p>
<ol class="steps">
    <li><strong>Open the docs</strong>: <?php if ($docs) : ?><a href="<?= route('/docs/') ?>" data-cast="off">/docs</a><?php else : ?>run <code>php cast serve</code> in development<?php endif ?>, the page called <em>Getting started</em> shows where every file goes.</li>
    <li><strong>Change this page</strong>: edit <code>resources/views/home/partials/home.cast.php</code>. The menu and footer are <code>resources/views/layouts/</code>.</li>
    <li><strong>Add a page</strong>: a route in <code>routes/web.php</code>, a controller in <code>app/Controllers</code>, a view in <code>resources/views/&lt;page&gt;/</code>. Its CSS and JS in <code>resources/css/&lt;page&gt;/</code> and <code>resources/js/&lt;page&gt;/</code> load by themselves.</li>
    <li><strong>Ask the console</strong>: <code>php cast list</code> shows every command, <code>php cast help migrate:sync</code> explains one.</li>
</ol>

<?php if ($demo) : ?>
    <div class="card">
        <h2>You are running the demo</h2>
        <p class="muted">Log in or register (top right) to try create, edit and delete on <em>Items</em>, a stats page and the JSON API. When you have seen enough, <a href="<?= route('/docs/#/getting-started') ?>" data-cast="off">Getting started</a> lists exactly what to delete to keep the structure and start your own project.</p>
    </div>
<?php endif ?>
