<?php
/**
 * A bordered box with an optional heading. The content between the tags is shown inside it.
 * @var string|null $title The heading shown at the top (leave out for no heading)
 */
?>
@props {
    ?string $title = '';
}
<section class="card">
    @if ($title !== '')
        <h2>@{ htchars($title) }</h2>
    @endif
    @{ $children }
</section>
