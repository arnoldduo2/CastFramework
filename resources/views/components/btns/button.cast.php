<?php
/**
 * Button component
 * @var string|null $label The text to display on the button
 * @var string|null $type The button type (e.g., "button", "submit", "reset")
 * @var string|null $href The URL to link to (if provided, renders an <a> tag instead of a <button>)
 * @var string|null $variant The button variant (e.g., "primary", "secondary", "ghost")
 */
?>
@props {
    ?string $label = 'Button';
    ?string $type = 'button';
    ?string $href = null;
    ?string $variant = 'primary';
}
@php($class = 'btn btn-' . $variant)
@if ($href)
    <a class=@htchars($class) href=@htchars($href)>@{ htchars($label) }</a>
@else
    <button type=@htchars($type) class=@htchars($class)>@{ htchars($label) }</button>
@endif
