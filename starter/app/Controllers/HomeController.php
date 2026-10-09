<?php

declare(strict_types=1);

namespace App\Controllers;

use Cast\Http\Controller;
use Cast\Http\Response;

/**
 * The welcome pages. A controller method receives the request (via $this->request) and returns a Response; `$this->view('folder.page', $data)`
 * renders resources/views/folder/page.cast.php. Make your own with:  php cast make:controller Orders
 */
class HomeController extends Controller
{
    /** GET /  the welcome page (resources/views/home/) */
    public function index(): Response
    {
        return $this->page('home');
    }

    /** GET /demo  how to try the demo (resources/views/demo/) */
    public function demo(): Response
    {
        return $this->page('demo');
    }

    /**
     * The data every page of this app passes to its view:
     *   parentName / pageName  pick the page's own css and js:  resources/css/<parentName>/<pageName>.css  and  resources/js/<parentName>/<pageName>.module.js
     *   authguard              'public' (anyone), 'auth' (guests only, like the login page) or 'private' (signed-in users)
     *   spa                    true: the page is loaded and swapped by the Cast client without a reload; remove it for a normal page load
     */
    private function page(string $name): Response
    {
        return $this->view("$name.$name", [
            'parentName' => $name,
            'pageName' => $name,
            'authguard' => 'public',
            'spa' => true,
        ]);
    }
}
