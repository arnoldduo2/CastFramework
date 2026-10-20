@php
/**
 * Btns Add New component. Describe what it is for.
 * @var string $label Describe label
 * @var 'primary'|'ghost' $variant Describe variant
 * @var ?string $href Describe href
 * @var bool $block Describe block
 */
$label ??= 'Add';
$variant ??= 'primary';
$href ??= null;
$block ??= false;
@endphp
<div class="btns-add-new">
    @{ $children }
</div>
