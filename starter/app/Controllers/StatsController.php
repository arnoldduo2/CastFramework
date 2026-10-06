<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Items;
use Cast\Http\Controller;
use Cast\Http\Response;

class StatsController extends Controller
{
    /** GET /stats: a second private page, so moving between Items and Stats swaps only the content area. */
    public function index(): Response
    {
        $items = Items::getAll(null, 'active');
        return $this->view('stats.stats', [
            'parentName' => 'stats',
            'pageName' => 'stats',
            'authguard' => 'private',
            'spa' => true,
            'count' => count($items),
            'units' => array_sum(array_column($items, 'qty')),
            'value' => array_sum(array_map(fn($i) => $i['qty'] * $i['price'], $items)),
        ]);
    }
}
