<?php
/**
 * A list item with two sides.
 *
 * @var string $right The text on the right
 * @slot left The HTML on the left side, usually badges
 * @slot footer Optional footer
 * @example <SelectItem right="Stock">
 *     <Slot name="left"><Badge text="New" /></Slot>
 * </SelectItem>
 * @deprecated Use ListRow instead
 */
?>
<li><?= $left ?? '' ?><?= $right ?></li>
