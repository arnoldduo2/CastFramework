<?php
/** @var string The visible text */
$text ??= 'Hello';

/** @var 'sm'|'md'|'lg' The size */
$size ??= 'md';
?>
<span class="badge badge-<?= $size ?>"><?= $text ?></span>
