<?php

declare(strict_types=1);

namespace App\Controllers;

use Cast\Http\Controller;
use Cast\Http\Response;

class StatusController extends Controller
{
    /** GET /api/status */
    public function index(): Response
    {
        return $this->success('ok', ['app' => config('app.name'), 'version' => \Cast\App\Application::VERSION]);
    }
}
