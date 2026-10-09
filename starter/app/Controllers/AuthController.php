<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Users;
use Cast\Http\Controller;
use Cast\Http\Response;
use Cast\Services\Auth;

/**
 * Login, register and logout for the demo.
 * `$this->validate([...])` checks the request with rules like 'required|email|unique:users,email' and, when it fails, sends the visitor
 * back with the messages under each field (JSON with the errors for a Cast or API request). `app('auth')` is the Auth service
 * (see AppServiceProvider and UserStore for where users come from).
 */
class AuthController extends Controller
{
    /** GET /login */
    public function login(): Response
    {
        return $this->page('login');
    }

    /** POST /login */
    public function attempt(): Response
    {
        $data = $this->validate(['email' => 'required|email', 'password' => 'required']);

        if (!app('auth')->attempt($data['email'], $data['password'])) {
            return $this->failed();
        }
        return $this->redirect('/items');
    }

    /** GET /register */
    public function register(): Response
    {
        return $this->page('register');
    }

    /** POST /register: validate, create the user (with the permission to use the demo's items), log them in */
    public function store(): Response
    {
        $data = $this->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',   // "confirmed" compares with the field password_confirmation
        ]);

        Users::query()->insert([
            'email' => $data['email'],
            'password' => Auth::hash($data['password']),
            'permissions' => '["manage-items"]',
        ]);
        app('auth')->attempt($data['email'], $data['password']);
        return $this->redirect('/items');
    }

    /** POST /logout */
    public function logout(): Response
    {
        app('auth')->logout();
        return $this->redirect('/');
    }

    /** One view serves both pages: resources/views/auth/auth.cast.php includes auth/partials/<pageName>.cast.php */
    private function page(string $name): Response
    {
        return $this->view('auth.auth', ['parentName' => 'auth', 'pageName' => $name, 'authguard' => 'auth', 'spa' => true]);
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
