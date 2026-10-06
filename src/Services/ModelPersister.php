<?php

declare(strict_types=1);

namespace Cast\Services;

use Cast\Contracts\HasLineItems;
use Cast\Contracts\Patchable;
use Cast\Contracts\Savable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Saves and patches records through models, including line items for header/lines documents.
 *
 *   $id = (new ModelPersister())->save(Invoices::class, $post, fn($data, $id) => audit('invoice saved', $id));
 */
final class ModelPersister
{
    /** @param class-string|Savable $model @param (callable(array, int|string): mixed)|null $after */
    public function save(string|object $model, array $data, ?callable $after = null): int|string
    {
        $instance = $this->instance($model, Savable::class);

        $id = $instance->save($data);
        if ($id === false || $id === null || $id === '') throw new RuntimeException('Error saving data.');
        if ($id === 'duplicate') throw new DuplicateEntryException();

        if ($instance instanceof HasLineItems) $instance->saveLineItems($id, $data);
        if ($after !== null) $after($data, $id);
        return $id;
    }

    /** @param class-string|Patchable $model @param (callable(array, int|string): mixed)|null $after */
    public function patch(string|object $model, array $data, ?callable $after = null): int|string
    {
        $instance = $this->instance($model, Patchable::class);

        $id = $instance->patch($data);
        if ($id === false || $id === null || $id === '') throw new RuntimeException('Error saving changes to data.');

        if ($instance instanceof HasLineItems) $instance->patchLineItems($id, $data);
        if ($after !== null) $after($data, $id);
        return $id;
    }

    private function instance(string|object $model, string $contract): object
    {
        $instance = is_string($model) ? new $model() : $model;
        if (!$instance instanceof $contract) {
            throw new InvalidArgumentException($instance::class . " must implement $contract.");
        }
        return $instance;
    }
}
