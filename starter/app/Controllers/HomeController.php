<?php

declare(strict_types=1);

namespace App\Controllers;

use Cast\Http\Controller;
use Cast\Http\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->view('home.home', ['parentName' => 'home', 'pageName' => 'home', 'authguard' => 'public']);
    }
}
