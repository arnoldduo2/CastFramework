<?php extract($data); ?>
<Card title="Add an item">
    <form method="post" action="<?= route('/items') ?>" class="row">
        <?= __csrf() ?>
        <label>Name <input name="name" required><?php __invalidFeedback('name'); ?></label>
        <label>Qty <input name="qty" type="number" min="0" value="0" required><?php __invalidFeedback('qty'); ?></label>
        <label>Price <input name="price" type="number" step="0.01" min="0" value="0" required><?php __invalidFeedback('price'); ?></label>
        <Btns.Button label="Add" type="submit" />
    </form>
</Card>

<Card title="Items">
    <?php if (!$items) : ?>
        <p class="muted">No items yet.</p>
    <?php else : ?>
        <table id="items-table">
            <thead>
                <tr><th>Name</th><th>Qty</th><th>Price</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr data-item="<?= jsonQuotes($item) ?>">
                        <td><?= htchars($item['name']) ?></td>
                        <td><?= (int) $item['qty'] ?></td>
                        <td><?= htchars(price($item['price'])) ?></td>
                        <td>
                            <button type="button" class="link js-restock">+1 qty</button>
                            <button type="button" class="link danger js-delete">Delete</button>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
    <p class="muted" id="items-message" role="status"></p>
</Card>
