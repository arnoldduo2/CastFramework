<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Items;
use Cast\Http\Controller;
use Cast\Http\Response;

class ItemsController extends Controller
{
    private array $rules = [
        'name' => 'required|string|min:2|max:80',
        'qty' => 'required|int|min:0',
        'price' => 'required|numeric|min:0',
    ];

    /** GET /items */
    public function index(): Response
    {
        return $this->view('items.items', [
            'parentName' => 'items',
            'pageName' => 'items',
            'authguard' => 'private',
            'spa' => true,
            'items' => Items::getAll(null, 'active', 'id', 'DESC'),
        ]);
    }

    /** GET /items/{id}/edit: an edit form for a modal (a Cast request with X-Cast-Type: modal; a browser gets the bare form) */
    public function edit(string $id): Response
    {
        $item = Items::getOne($id);
        if (!$item) return $this->error('That item does not exist.', 404);

        return $this->view('items.modals.edit', ['item' => $item, 'modalClass' => 'modal-sm', 'form' => 'edit-item-form', 'fragment' => true]);
    }

    /** POST /items (a normal form, or JSON) */
    public function store(): Response
    {
        $data = $this->validate($this->rules);
        $id = Items::query()->insert($data + ['created_at' => date('Y-m-d H:i:s')]);

        return $this->request->expectsJson()
            ? $this->success('Item added.', ['id' => $id], 201)
            : $this->redirect('/items');
    }

    /** PUT /items/{id} (JSON) */
    public function update(string $id): Response
    {
        if (!Items::getOne($id)) return $this->error('That item does not exist.', 404);

        $data = $this->validate($this->rules);
        Items::updateColumns($data, $id);
        return $this->success('Item updated.', ['id' => (int) $id] + $data);
    }

    /** DELETE /items/{id} (JSON) */
    public function destroy(string $id): Response
    {
        return Items::deleteRows($id)
            ? $this->success('Item deleted.', ['id' => (int) $id])
            : $this->error('That item does not exist.', 404);
    }
}
