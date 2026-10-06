<?php

declare(strict_types=1);

namespace Cast\Contracts;

/** The view layer. Implemented by {@see \Cast\Core\View}; swap it to change the template engine. */
interface ViewRenderer
{
    /** Render a view ('module.page' or 'module/page') to a string. */
    public function render(string $view, array $data = []): string;

    /** Render a component ('btns.add-new-btn'). With `$asVar`, returns what the component file returns. */
    public function component(string $name, array|string $data = [], bool $asVar = false): mixed;

    /** Render a partial or layout and echo it. */
    public function partial(string $name, array $data = []): string;

    /** The `<link>` / `<script>` tag for a module file ('accounting.assets', 'css'|'js'), or ''. */
    public function modules(string $name, string $type = 'js'): string;
}
