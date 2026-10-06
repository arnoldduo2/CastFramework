<?php
$parentName = $data['parentName'] ?? '';
$pageName = $data['pageName'] ?? '';
?>
    </main>
    <footer class="foot">Built with CastFramework</footer>
    <?= __modules('app.app', 'js') ?>
    <?= __modules("$parentName.$pageName", 'js') ?>
</body>

</html>
