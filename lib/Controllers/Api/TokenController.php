<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Cast\Http\Controller;
use Cast\Http\Response;
use Cast\Services\ApiTokens;

/**
 * API tokens: log in with an email and password to get a token, then send it as `Authorization: Bearer <token>`.
 * Only a hash of the token is stored (api_tokens table); the token itself is shown once. Console: php cast token:create <login>
 */
class TokenController extends Controller
{
    /** POST /api/auth/token {email, password, name?, abilities?} => a bearer token (shown once) */
    public function issue(): Response
    {
        $data = $this->validate(['email' => 'required|email', 'password' => 'required', 'name' => 'sometimes|string|max:100']);

        $user = app('auth')->verify($data['email'], $data['password']);
        if ($user === null) return $this->error('Those details do not match our records.', 401);

        /** @var ApiTokens $tokens */
        $tokens = app('tokens');
        $abilities = array_values(array_filter((array) $this->request->input('abilities', ['*']), 'is_string')) ?: ['*'];
        $issued = $tokens->issue($user['id'], $data['name'] ?? 'api', $abilities, 30 * 86400);

        return $this->success('Token created.', [
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
            'user' => ['id' => $user['id'], 'email' => $user['email']],
        ], 201);
    }

    /** GET /api/me */
    public function me(): Response
    {
        $user = $this->request->user() ?? app('guard')->user();
        return $this->success('', ['user' => ['id' => $user['id'], 'email' => $user['email'], 'permissions' => $user['permissions']]]);
    }

    /** DELETE /api/auth/token: revoke the token used for this request */
    public function revoke(): Response
    {
        $token = $this->request->token();
        if ($token === null) return $this->error('This request was not made with a token.', 400);

        app('tokens')->revoke($token['id']);
        return $this->success('Token revoked.');
    }
}
