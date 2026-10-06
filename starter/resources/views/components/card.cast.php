<?php $title ??= ''; ?>
<section class="card">
    <?php if ($title !== '') : ?><h2><?= htchars($title) ?></h2><?php endif ?>
    <?= $children ?>
</section>
