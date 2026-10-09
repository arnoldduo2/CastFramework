<?php
/**
 * Banner shown above the login and register forms: what the demo is for, with links to the docs.
 * A component is a file in resources/views/components: <CrudBanner /> (the file name in kebab-case) is replaced by this file's output.
 * The docblock below is read by the editor extension and by `php cast components` (props, types, descriptions).
 * @var string|null $title The headline (default: "Use the framework for CRUD operations")
 */
$title ??= 'Use the framework for CRUD operations';
$docs = config('app.env') !== 'production' || config('cdocs.enabled');
?>
<div class="banner">
    <div>
        <strong><?= htchars($title) ?></strong>
        <p>Create, read, update and delete rows with a form, a validated request and a table: this demo does it end to end.</p>
    </div>
    <?php if ($docs) : ?>
        <nav>
            <a href="<?= route('/cdocs/#/getting-started') ?>" data-cast="off">Getting started</a>
            <a href="<?= route('/cdocs/#/models-and-the-query-builder') ?>" data-cast="off">Models</a>
            <a href="<?= route('/cdocs/#/validation') ?>" data-cast="off">Validation</a>
            <a href="<?= route('/cdocs/#/migrations') ?>" data-cast="off">Migrations</a>
        </nav>
    <?php endif ?>
</div>
