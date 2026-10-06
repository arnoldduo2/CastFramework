<?php

declare(strict_types=1);

use Cast\Http\Request;
use Cast\Http\Response;

if (!function_exists('request')) {
    /** The current request, or one input value: `request('email')`. */
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = Request::current();
        return $key === null ? $request : $request->input($key, $default);
    }
}

if (!function_exists('response')) {
    /** `response()->json(...)`-style helper: returns a Response (html by default). */
    function response(string $body = '', int $status = 200): Response
    {
        return Response::html($body, $status);
    }
}

if (!function_exists('getPost')) {
    /**
     * The request body as a sanitised array: the JSON body by default, or the form-encoded body with `$form = true`.
     * Sanitising is the `request.sanitizer` config callable (default: trim). Use the Request object for raw input.
     */
    function getPost(bool $form = false): array
    {
        return Request::current()->postData($form);
    }
}
