<?php extract($data); ?>
<Card title="Stock summary">
    <div class="stats-grid">
        <div class="stat"><span class="muted">Items</span><strong id="stat-items"><?= (int) $count ?></strong></div>
        <div class="stat"><span class="muted">Units in stock</span><strong id="stat-units"><?= (int) $units ?></strong></div>
        <div class="stat"><span class="muted">Stock value</span><strong id="stat-value"><?= htchars(price($value)) ?></strong></div>
    </div>
</Card>
