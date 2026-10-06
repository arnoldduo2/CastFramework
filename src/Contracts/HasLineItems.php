<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * A header model (invoice, order...) that also stores line items. {@see \Cast\Services\ModelPersister} calls these
 * after the header is saved or patched, with the header's id/number and the full request data.
 */
interface HasLineItems
{
    public function saveLineItems(int|string $parentId, array $data): void;

    public function patchLineItems(int|string $parentId, array $data): void;
}
