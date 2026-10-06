<?php

declare(strict_types=1);

namespace App\Controllers;

use Cast\Http\Controller;
use Cast\Http\Response;

class AuthController extends Controller
{
    public function login(): Response
    {
        return $this->view('auth.auth', ['parentName' => 'auth', 'pageName' => 'login', 'authguard' => 'auth']);
    }

    public function attempt(): Response
    {
        $data = $this->validate(['email' => 'required|email', 'password' => 'required']);

        if (!app('auth')->attempt($data['email'], $data['password'])) {
            return $this->failed();
        }
        return $this->redirect('/items');
    }

    public function logout(): Response
    {
        app('auth')->logout();
        return $this->redirect('/');
    }

    private function failed(): Response
    {
        if ($this->request->expectsJson()) {
            return $this->error('Those details do not match our records.', 401);
        }
        \Cast\Core\Session::flash('input_errors', ['email' => 'Those details do not match our records.']);
        return $this->redirect('/login');
    }
}
