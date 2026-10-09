<?php
/** The bottom of every page: closes what layouts/header opened, shows the fixed footer, and loads the page's scripts. */
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
$docs = config('app.env') !== 'production' || config('docs.enabled');
?>
    </main>
    <footer class="foot">
        Built with CastFramework v<?= htchars(\Cast\App\Application::VERSION) ?>
        <?php if ($docs) : ?> &middot; <a href="<?= route('/docs/') ?>" data-cast="off">Documentation</a><?php endif ?>
        &middot; <a href="https://github.com/arnoldduo2/CastFramework" target="_blank" rel="noopener">GitHub</a>
    </footer>
    <?= __modules('app.app', 'js') ?>
    <?= __modules("$parentName.$pageName", 'js') ?>
</body>

</html>
