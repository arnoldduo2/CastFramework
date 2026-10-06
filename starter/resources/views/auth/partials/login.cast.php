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
    </form>
</Card>
