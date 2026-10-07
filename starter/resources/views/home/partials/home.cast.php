<Card title="Welcome">
    <p>This is a small app built with <strong>CastFramework</strong>: routing for every HTTP verb, a global CSRF token,
        validation, a SQLite model, a JSON API, and views written with component tags.</p>
    <p>Log in with <code>admin@example.com</code> / <code>password</code>, then manage the demo items.</p>
    <Btns.Button label="Open the items" href={route('/items')} />
    <Btns.Button label="See an error page" href={route('/does-not-exist')} variant="ghost" />
    <Btns.Button label="Read the docs" href="https://github.com/arnoldduo2/CastFramework/blob/main/docs/GETTING-STARTED.md" variant="ghost" />
</Card>

<div class="guide stack">
    <Card title="How this app works (the workflow)">
        <p>Every page follows the same path, and each step has one place to live:</p>
        <ol>
            <li><strong>Route</strong>: <code>routes/web.php</code> maps a URL and verb to a controller method. <code>routes/api.php</code> does the same for the JSON API under <code>/api</code>.</li>
            <li><strong>Controller</strong>: <code>app/Controllers</code>. It validates the request, uses a model, and returns <code>$this-&gt;view('items.items', [...])</code>.</li>
            <li><strong>View</strong>: <code>resources/views/items/items.cast.php</code> includes the layout header and footer and the page's <code>partials/</code>. Reusable pieces are components, written as tags like <code>&lt;Btns.Button /&gt;</code>.</li>
            <li><strong>CSS and JS load by name</strong>: a page named <code>items</code> in the folder <code>items</code> automatically gets <code>resources/css/items/items.css</code> and <code>resources/js/items/items.module.js</code>. No <code>&lt;link&gt;</code> or <code>&lt;script&gt;</code> to add.</li>
            <li><strong>SPA</strong>: pages with <code>'spa' =&gt; true</code> load once, then links swap only the page content (no full reload). Other pages are normal page loads. The server decides, the controller does not change.</li>
            <li><strong>Data</strong>: <code>app/Models</code> for queries, <code>database/migrations</code> for the tables, <code>database/seeders</code> for demo data.</li>
        </ol>
    </Card>

    <Card title="Where everything is">
        <table>
            <thead><tr><th>You want to change</th><th>Open</th></tr></thead>
            <tbody>
                <tr><td>A page's HTML</td><td><code>resources/views/&lt;page&gt;/partials/&lt;page&gt;.cast.php</code></td></tr>
                <tr><td>The header, footer, menu</td><td><code>resources/views/layouts/header.cast.php</code> and <code>footer.cast.php</code></td></tr>
                <tr><td>A reusable piece (button, card)</td><td><code>resources/views/components/</code> (see <code>php cast components</code>)</td></tr>
                <tr><td>A popup form</td><td><code>resources/views/&lt;page&gt;/modals/</code></td></tr>
                <tr><td>Styles for the whole app / one page</td><td><code>resources/css/app.css</code> / <code>resources/css/&lt;page&gt;/&lt;page&gt;.css</code></td></tr>
                <tr><td>Scripts for the whole app / one page</td><td><code>resources/js/app/app.module.js</code> / <code>resources/js/&lt;page&gt;/&lt;page&gt;.module.js</code></td></tr>
                <tr><td>URLs</td><td><code>routes/web.php</code>, <code>routes/api.php</code></td></tr>
                <tr><td>What happens on a URL</td><td><code>app/Controllers</code></td></tr>
                <tr><td>Database tables and queries</td><td><code>database/migrations</code>, <code>app/Models</code></td></tr>
                <tr><td>Settings and secrets</td><td><code>config/*.php</code>, <code>.env</code></td></tr>
                <tr><td>Your own helper functions</td><td><code>app/helpers/</code></td></tr>
            </tbody>
        </table>
        <p class="muted">Every command and flag: <code>php cast list</code>, then <code>php cast help &lt;command&gt;</code>. Full guide: <a href="https://github.com/arnoldduo2/CastFramework/blob/main/docs/GETTING-STARTED.md">Getting started</a> · <a href="https://github.com/arnoldduo2/CastFramework/blob/main/docs/COMMANDS.md">All commands</a> · <a href="https://github.com/arnoldduo2/CastFramework#readme">Full documentation</a>.</p>
    </Card>

    <Card title="Start your own project (delete this demo)">
        <p>This is a demo. When you have seen enough, remove what belongs to it and keep the structure:</p>
        <ol>
            <li>Delete the demo pages: <code>resources/views/items</code>, <code>resources/views/stats</code>, <code>resources/views/auth</code> (keep it if you want the login), and their <code>resources/css/items</code>, <code>resources/css/stats</code>, <code>resources/js/items</code>, <code>resources/js/stats</code>.</li>
            <li>Delete the demo code: <code>app/Controllers/ItemsController.php</code>, <code>StatsController.php</code>, <code>Api/</code>, <code>app/Models/Items.php</code>, and the routes that use them in <code>routes/web.php</code> and <code>routes/api.php</code>.</li>
            <li>Delete the demo table: <code>database/migrations/…_create_items_table.php</code> (keep <code>users</code> and <code>api_tokens</code> if you keep the login and API), then <code>php cast migrate:fresh --seed</code>.</li>
            <li>Replace this page: edit <code>resources/views/home/partials/home.cast.php</code>.</li>
            <li>Start building: <code>php cast make:controller Orders</code>, <code>php cast make:migration create_orders_table</code>, <code>php cast make:component Btns.AddNew</code>.</li>
        </ol>
        <p>Or start clean in an empty folder: <code>composer require anode/cast-framework</code> then <code>php cast init</code> (without <code>--demo</code>).</p>
    </Card>
</div>
