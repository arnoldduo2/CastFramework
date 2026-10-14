<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Items;
use Cast\Http\Controller;
use Cast\Http\Response;

/**
 * A second signed-in page. Moving between Items and Stats swaps only the content area (the SPA client), because both set 'spa' => true.
 * A controller with a single index() method can be registered as  Router::get('/stats', StatsController::class);
 */
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
