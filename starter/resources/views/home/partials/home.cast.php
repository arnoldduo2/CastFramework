<Card title="Welcome">
    <p>This is a small app built with <strong>CastFramework</strong>: routing for every HTTP verb, a global CSRF token,
        validation, a SQLite model, and views written with component tags.</p>
    <p>Log in with <code>admin@example.com</code> / <code>password</code>, then manage the demo items.</p>
    <Btns.Button label="Open the items" href={route('/items')} />
    <Btns.Button label="See an error page" href={route('/does-not-exist')} variant="ghost" />
</Card>
