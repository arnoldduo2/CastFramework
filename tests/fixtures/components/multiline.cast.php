<?php
/**
 * A card with a title.
 *
 * Wraps its children in a bordered box.
 *
 * @var string $title The heading shown at the top of the card,
 *                    which may be long and wraps onto a second line
 * @var bool   $compact Use less padding [optional]
 * @param int|float $total - The amount shown in the corner
 */
$compact ??= false;
?>
<section class="card"><h2><?= $title ?></h2><?= $children ?></section>
