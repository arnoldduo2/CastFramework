<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Items;
use Cast\Http\Controller;
use Cast\Http\Response;

/** The items as a JSON API: the same data as the pages, for a front-end framework or another server. */
class ItemsController extends Controller
{
    private array $rules = [
        'name' => 'required|string|min:2|max:80',
        'qty' => 'required|int|min:0',
        'price' => 'required|numeric|min:0',
    ];

    /** GET /api/items?page=1&per_page=20 */
    public function index(): Response
    {
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = min(100, max(1, (int) $this->request->query('per_page', 20)));
        $result = Items::paginate($page, $perPage, 'id');

        return $this->success('', [
            'items' => $result['data'],
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'last_page' => $result['last_page'],
        ]);
    }

    /** GET /api/items/{id} */
    public function show(string $id): Response
    {
        $item = Items::getOne($id);
        return $item ? $this->success('', ['item' => $item]) : $this->error('That item does not exist.', 404);
    }

    /** POST /api/items */
    public function store(): Response
    {
        $data = $this->validate($this->rules);
        $id = Items::query()->insert($data + ['created_at' => date('Y-m-d H:i:s')]);
        return $this->success('Item added.', ['item' => Items::getOne($id)], 201);
    }

    /** PUT /api/items/{id} */
    public function update(string $id): Response
    {
        if (!Items::getOne($id)) return $this->error('That item does not exist.', 404);

        Items::updateColumns($this->validate($this->rules), $id);
        return $this->success('Item updated.', ['item' => Items::getOne($id)]);
    }

    /** DELETE /api/items/{id} */
    public function destroy(string $id): Response
    {
        return Items::deleteRows($id) ? $this->success('Item deleted.') : $this->error('That item does not exist.', 404);
    }
}
