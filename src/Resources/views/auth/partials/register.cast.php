<?php
/** The register page (route GET /register, AuthController::register). It posts to AuthController::store, which validates, creates the user and logs them in. */
?>
<div class="auth-card stack">
    <CrudBanner title="Register, then try CRUD on Items" />
    <Card title="Create an account">
        <form method="post" action="<?= route('/register') ?>" class="stack" data-cast-form>
            <?= __csrf() ?>
            <label>Email
                <input type="email" name="email" value="<?= htchars((string) (\Cast\Core\Session::peekFlash('old')['email'] ?? '')) ?>" required autofocus>
                <?php __invalidFeedback('email'); ?>
            </label>
            <label>Password
                <input type="password" name="password" minlength="8" placeholder="8 characters or more" required>
                <?php __invalidFeedback('password'); ?>
            </label>
            <label>Repeat the password
                <input type="password" name="password_confirmation" minlength="8" required>
            </label>
            <p class="validation" data-cast-message role="alert"></p>
            <Btns.Button label="Register" type="submit" />
            <p class="muted">Already have an account? <a href="<?= route('/login') ?>">Log in</a></p>
        </form>
    </Card>
</div>
