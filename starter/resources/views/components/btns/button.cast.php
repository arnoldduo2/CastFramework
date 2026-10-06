<?php
$label ??= 'Button';
$type ??= 'button';
$href ??= null;
$variant ??= 'primary';
$class = 'btn btn-' . $variant;
?>
<?php if ($href) : ?>
    <a class="<?= htchars($class) ?>" href="<?= htchars($href) ?>"><?= htchars($label) ?></a>
<?php else : ?>
    <button type="<?= htchars($type) ?>" class="<?= htchars($class) ?>"><?= htchars($label) ?></button>
<?php endif ?>
