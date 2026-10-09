<?php
/**
 * The edit popup. ItemsController::edit() renders it with 'modalClass' and 'form': the SPA client opens it in a dialog when a link asks for
 * a modal (Cast.load(url, {type: 'modal'})). The hidden _method field makes the browser's POST a PUT; `data-cast-form` submits it without a reload.
 */
extract($data);
?>
<h2>Edit <?= htchars($item['name']) ?></h2>
<form method="post" action="<?= route('/items/' . (int) $item['id']) ?>" class="stack" data-cast-form id="edit-item-form">
    <?= __csrf() ?>
    <input type="hidden" name="_method" value="PUT">
    <label>Name <input name="name" value="<?= htchars($item['name']) ?>" required></label>
    <label>Qty <input name="qty" type="number" min="0" value="<?= (int) $item['qty'] ?>" required></label>
    <label>Price <input name="price" type="number" step="0.01" min="0" value="<?= htchars((string) $item['price']) ?>" required></label>
    <p class="validation" data-cast-message role="alert"></p>
    <Btns.Button label="Save" type="submit" />
</form>
