<?php
/** The login page (route GET /login, AuthController::login). The form posts to /login; `data-cast-form` makes the SPA client submit it without a reload and show validation errors under the fields. */
?>
<div class="auth-card stack">
    <CrudBanner />
    <Card title="Log in">
        <form method="post" action="<?= route('/login') ?>" class="stack" data-cast-form>
            <?= __csrf() ?>
            <label>Email
                <input type="email" name="email" value="<?= htchars((string) (\Cast\Core\Session::peekFlash('old')['email'] ?? '')) ?>" required autofocus>
                <?php __invalidFeedback('email'); ?>
            </label>
            <label>Password
                <input type="password" name="password" required>
                <?php __invalidFeedback('password'); ?>
            </label>
            <p class="validation" data-cast-message role="alert"></p>
            <Btns.Button label="Log in" type="submit" />
            <p class="muted">New here? <a href="<?= route('/register') ?>">Create an account</a>. Demo login: admin@example.com / password</p>
        </form>
    </Card>
</div>
