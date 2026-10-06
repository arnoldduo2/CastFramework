<?php

declare(strict_types=1);

use Cast\Contracts\Rule;
use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Http\Request;
use Cast\Validation\LegacyRules;
use Cast\Validation\ValidationException;
use Cast\Validation\Validator;

/** @return array<string, list<string>> */
function errs(array $data, array $rules, array $messages = [], array $labels = []): array
{
    return Validator::make($data, $rules, $messages, $labels)->errors();
}

function passes(mixed $value, string|array $rule, array $extra = []): bool
{
    return Validator::make(['f' => $value] + $extra, ['f' => $rule])->passes();
}

test('Validator: required treats null, empty string, empty array and blank strings as missing', function () {
    foreach ([null, '', [], '   ', "\n"] as $empty) ok(!passes($empty, 'required'), var_export($empty, true));
    foreach (['a', 0, '0', false, [1]] as $filled) ok(passes($filled, 'required'), var_export($filled, true));
    ok(!Validator::make([], ['f' => 'required'])->passes(), 'absent key');
});

test('Validator: optional fields skip their rules when empty; sometimes skips absent fields', function () {
    ok(Validator::make(['email' => ''], ['email' => 'email'])->passes());
    ok(Validator::make([], ['email' => 'email|min:50'])->passes());
    ok(!Validator::make(['email' => 'nope'], ['email' => 'email'])->passes());
    ok(Validator::make([], ['x' => 'sometimes|required'])->passes(), 'absent => skipped');
    ok(!Validator::make(['x' => ''], ['x' => 'sometimes|required'])->passes(), 'present => checked');
    ok(Validator::make(['x' => null], ['x' => 'nullable|string'])->passes());
});

test('Validator: type rules', function () {
    foreach (['5', 5, '-3'] as $v) ok(passes($v, 'int'), "int $v");
    foreach (['5.5', 'abc', '1e3x'] as $v) ok(!passes($v, 'int'), "not int $v");
    ok(passes('12.5', 'numeric') && passes(3, 'numeric') && !passes('12a', 'numeric'));
    ok(passes('1.5', 'float') && !passes('x', 'float'));
    foreach ([true, false, 0, 1, '0', '1', 'true', 'false'] as $v) ok(passes($v, 'bool'), 'bool ' . var_export($v, true));
    ok(!passes('yes', 'bool'));
    ok(passes('text', 'string') && !passes(5, 'string'));
    ok(passes([1], 'array') && !passes('x', 'array'));
    ok(passes('{"a":1}', 'json') && !passes('{a:1}', 'json') && !passes('', 'json') === false);
    ok(passes('a@b.co', 'email') && !passes('a@', 'email') && !passes('a b@c.d', 'email'));
    ok(passes('https://x.test/a?b=1', 'url') && !passes('x.test', 'url'));
    ok(passes('192.168.1.1', 'ip') && passes('::1', 'ip') && !passes('999.1.1.1', 'ip'));
});

test('Validator: legacy aliases number and integer behave like numeric and int', function () {
    ok(passes('12.5', 'number') && !passes('x', 'number'));
    ok(passes('7', 'integer') && !passes('7.1', 'integer'));
    ok(passes('true', 'boolean'));
});

test('Validator: size rules work on numbers (value), strings (length) and arrays (count)', function () {
    ok(passes(5, 'min:3') && !passes(2, 'min:3') && passes('abc', 'min:3') && !passes('ab', 'min:3') && passes([1, 2, 3], 'min:3') && !passes([1], 'min:3'));
    ok(passes(5, 'max:5') && !passes(6, 'max:5') && passes('abcde', 'max:5') && !passes('abcdef', 'max:5'));
    ok(passes(5, 'between:1,10') && !passes(11, 'between:1,10') && passes('abc', 'between:2,4') && !passes('a', 'between:2,4'));
    ok(passes('abcd', 'length:4') && !passes('abc', 'length:4') && passes('日本語', 'length:3'), 'multibyte length');
    ok(passes('1.5', 'min:1.5'), 'decimal limit');
});

test('Validator: in / not_in / regex / alpha / alpha_num', function () {
    ok(passes('b', 'in:a,b,c') && !passes('d', 'in:a,b,c') && passes(2, 'in:1,2,3'));
    ok(passes('d', 'not_in:a,b,c') && !passes('a', 'not_in:a,b,c'));
    ok(passes('AB12', ['regex:/^[A-Z]{2}\d{2}$/']) && !passes('ab12', ['regex:/^[A-Z]{2}\d{2}$/']));
    ok(passes('a|b', ['regex:/^a|b$/']), 'a pipe inside a regex needs the array form');
    ok(passes('Émile', 'alpha') && !passes('Em1le', 'alpha') && passes('Em1le', 'alpha_num') && !passes('Em le', 'alpha_num'));
});

test('Validator: dates', function () {
    ok(passes('2024-02-29', 'date') && !passes('not a date', 'date'));
    ok(passes('29/02/2024', 'date:d/m/Y') && !passes('31/02/2024', 'date:d/m/Y') && !passes('2024-02-29', 'date:d/m/Y'));
    ok(passes('2020-01-01', 'before:2021-01-01') && !passes('2022-01-01', 'before:2021-01-01'));
    ok(passes('2022-01-01', 'after:2021-01-01') && !passes('2020-01-01', 'after:2021-01-01'));
});

test('Validator: same, confirmed and required_if', function () {
    eq([], errs(['pw' => 'a', 'pw2' => 'a'], ['pw2' => 'same:pw']));
    ok(isset(errs(['pw' => 'a', 'pw2' => 'b'], ['pw2' => 'same:pw'])['pw2']));
    eq([], errs(['p' => 'x', 'p_confirmation' => 'x'], ['p' => 'confirmed']));
    ok(isset(errs(['p' => 'x', 'p_confirmation' => 'y'], ['p' => 'confirmed'])['p']));
    ok(isset(errs(['type' => 'company', 'name' => ''], ['name' => 'required_if:type,company'])['name']));
    eq([], errs(['type' => 'person', 'name' => ''], ['name' => 'required_if:type,company']));
    eq([], errs(['type' => 'company', 'name' => 'Acme'], ['name' => 'required_if:type,company']));
});

test('Validator: uploaded files (file, max_kb, mimes)', function () {
    $file = ['name' => 'a.PNG', 'tmp_name' => '/tmp/x', 'size' => 2048, 'error' => UPLOAD_ERR_OK];
    ok(passes($file, 'file') && !passes('text', 'file') && !passes($file['error'] = UPLOAD_ERR_NO_FILE, 'file'));
    $file['error'] = UPLOAD_ERR_OK;
    ok(passes($file, 'max_kb:2') && !passes($file, 'max_kb:1'));
    ok(passes($file, 'mimes:png,jpg') && !passes($file, 'mimes:pdf'));
});

test('Validator: unique and exists use bound queries (and work on tables without an id column)', function () {
    sqlite("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT); INSERT INTO users (email) VALUES ('a@x.com'), ('b@x.com');
            CREATE TABLE tags (name TEXT); INSERT INTO tags VALUES ('php');");
    ok(!passes('a@x.com', 'unique:users,email') && passes('new@x.com', 'unique:users,email'));
    ok(passes('a@x.com', 'unique:users,email,1'), 'ignore the row being edited');
    ok(!passes('a@x.com', 'unique:users,email,2'));
    ok(passes('a@x.com', 'exists:users,email') && !passes('zz@x.com', 'exists:users,email'));
    ok(!passes('php', 'unique:tags,name') && passes('js', 'unique:tags,name'), 'no id column');
    ok(passes("x' OR '1'='1", 'unique:users,email'), 'values are bound, not interpolated');
    throws(InvalidArgumentException::class, fn() => passes('x', 'unique:users;DROP TABLE users,email'), 'Invalid table');
});

test('Validator: password rule uses the policy with options', function () {
    ok(passes('Secret123', 'password') && !passes('secret', 'password') && !passes('SECRET123', 'password'));
    ok(passes('Secret1234567', 'password:len=12') && !passes('Secret123', 'password:len=12'));
    ok(!passes('Secret123', 'password:sp=1') && passes('Secret123!', 'password:sp=1'));
});

test('Validator: default messages, labels and custom messages (per rule and per field.rule)', function () {
    eq(['Email is required.'], errs([], ['email' => 'required'])['email']);
    eq(['First Name must be at least 3.'], errs(['first_name' => 'ab'], ['first_name' => 'min:3'])['first_name']);
    eq(['Age must be between 18 and 99.'], errs(['age' => 5], ['age' => 'between:18,99'])['age']);
    eq(['Role must be one of: a, b.'], errs(['role' => 'z'], ['role' => 'in:a,b'])['role']);
    eq(['Your email is required.'], errs([], ['email' => 'required'], [], ['email' => 'Your email'])['email'], 'labels');
    eq(['Fill this in'], errs([], ['email' => 'required'], ['required' => 'Fill this in'])['email'], 'per rule');
    eq(['Need email!'], errs([], ['email' => 'required'], ['email.required' => 'Need email!', 'required' => 'x'])['email'], 'per field.rule wins');
    eq(['Name must be at least 5.'], errs(['name' => 'ab'], ['name' => 'min:5'], ['name.min' => ':field must be at least :0.'])['name']);
});

test('Validator: required stops other rules on the field; other failures collect all messages', function () {
    eq(1, count(errs([], ['x' => 'required|email|min:5'])['x']));
    eq(2, count(errs(['x' => 'a'], ['x' => 'email|min:5'])['x']));
    $v = Validator::make(['a' => '', 'b' => 'x'], ['a' => 'required', 'b' => 'min:3']);
    eq(['a', 'b'], array_keys($v->errors()));
    eq(['a' => 'A is required.', 'b' => 'B must be at least 3.'], $v->firstErrors());
});

test('Validator: validated() returns only fields that have rules; validate() throws with the errors', function () {
    $v = Validator::make(['name' => 'Ann', 'admin' => 1, 'age' => '30'], ['name' => 'required', 'age' => 'int']);
    ok($v->passes());
    eq(['name' => 'Ann', 'age' => '30'], $v->validated(), 'mass-assignment protection');
    eq(['name' => 'Ann', 'age' => '30'], $v->validate());

    $e = throws(ValidationException::class, fn() => Validator::make(['name' => ''], ['name' => 'required'])->validate());
    eq(['name' => 'Name is required.'], $e->errors());
    eq(['name' => ['Name is required.']], $e->all());
    eq(422, $e->getCode());
});

test('Validator: custom rules: Rule objects, closures, extend()', function () {
    $even = new class implements Rule {
        public function passes(string $field, mixed $value, array $data): bool { return $value % 2 === 0; }
        public function message(): string { return ':field must be even.'; }
    };
    eq(['Num must be even.'], errs(['num' => 3], ['num' => ['required', $even]])['num']);
    eq([], errs(['num' => 4], ['num' => ['required', $even]]));

    eq(['Code is invalid.'], errs(['code' => 'x'], ['code' => [fn($value, $data, $field) => $value === 'ok']])['code']);
    eq([], errs(['code' => 'ok'], ['code' => [fn($value) => $value === 'ok']]));

    Validator::extend('multiple_of', fn($value, $params) => $value % (int) $params[0] === 0, ':field must be a multiple of :0.');
    eq(['Qty must be a multiple of 5.'], errs(['qty' => 7], ['qty' => 'multiple_of:5'])['qty']);
    eq([], errs(['qty' => 10], ['qty' => 'multiple_of:5']));

    throws(InvalidArgumentException::class, fn() => errs(['a' => 'x'], ['a' => 'no_such_rule']), 'Unknown validation rule');
});

test('Request::validate() and Controller::validate() return clean data or throw', function () {
    boot_app();
    $request = req('POST', '/x', ['email' => 'a@b.co', 'extra' => 'x']);
    eq(['email' => 'a@b.co'], $request->validate(['email' => 'required|email']));
    throws(ValidationException::class, fn() => req('POST', '/x', ['email' => 'bad'])->validate(['email' => 'email']));

    Request::setCurrent(req('POST', '/x', ['n' => '5']));
    $c = new class extends \Cast\Http\Controller {
        public function run(): array { return $this->validate(['n' => 'required|int']); }
    };
    eq(['n' => '5'], $c->run());
});

test('Kernel: an invalid form answers 422 JSON for ajax clients (R11 shape with data.errors)', function () {
    boot_app();
    $_SESSION['_csrf_token'] = 't';
    Router::post('/users', fn(array $all) => Validator::make($all, ['email' => 'required|email', 'age' => 'int'])->validate());
    $r = handle(req('POST', '/users', ['email' => 'bad', 'age' => 'x'], ['HTTP_X_CSRF_TOKEN' => 't', 'HTTP_ACCEPT' => 'application/json']));
    eq(422, $r->statusCode());
    eq('error', $r->data()['status']);
    eq(['email' => 'Email must be a valid email address.', 'age' => 'Age must be a whole number.'], $r->data()['data']['errors']);
});

test('Kernel: an invalid browser form flashes input_errors and old input, then redirects back', function () {
    boot_app();
    $_SESSION['_csrf_token'] = 't';
    Router::post('/users', fn(array $all) => Validator::make($all, ['email' => 'required|email'])->validate());
    $form = new Request('POST', '/users', [], 'email=bad&password=secret&name=Ann', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_X_CSRF_TOKEN' => 't', 'HTTP_REFERER' => '/users/new']);
    $r = handle($form);
    eq(302, $r->statusCode());
    eq('/users/new', $r->getHeader('Location'));
    eq(['email' => 'Email must be a valid email address.'], Session::peekFlash('input_errors'));
    $old = Session::peekFlash('old');
    eq('Ann', $old['name']);
    ok(!isset($old['password']) && !isset($old['_token']), 'secrets are not flashed back');

    ob_start();
    __invalidFeedback('email');
    has('Email must be a valid email address.', ob_get_clean());
    ob_start();
    __invalidFeedback('email');
    eq('', ob_get_clean(), 'shown once per field');
});

test('LegacyRules: same messages and result shape as the old controller method', function () {
    boot_app();
    eq(0, LegacyRules::run(['email' => 'a@b.co|required|email', 'qty' => '5|required|number', 'name' => 'Ann|required||3']));
    eq([], Session::peekFlash('input_errors'));

    eq(5, LegacyRules::run([
        'first_name' => '|required',
        'email' => 'nope|required|email',
        'qty' => 'x|required|number',
        'pass' => 'ab|required||8',
        'customer-id' => '|required',
    ]));
    eq([
        'first_name' => 'First Name: Is Required!',
        'email' => 'Email: Please Enter A Valid Email!',
        'qty' => 'Qty: Enter Number Only!',
        'pass' => 'Pass: Expected At Least 8 Characters!',
        'customer-id' => 'Customer Id: Is Required!',
    ], Session::peekFlash('input_errors'));

    $json = LegacyRules::run(['a' => '|required'], true);
    eq('{"a":"A: Is Required!"}', $json);
    eq(1, LegacyRules::run(['a' => '|required'], false, ['required' => 'Missing!']));
    eq(['a' => 'A: Missing!'], Session::peekFlash('input_errors'), 'custom feedback messages');
    eq(1, formValidation(['a' => '|required']), 'old helper name');
});

test('Param helpers: validateParam, checkRouteParams, checkPostParams', function () {
    eq(5, validateParam('5', 'int'));
    eq(null, validateParam('abc', 'int'));
    eq(null, validateParam('', 'string'));
    eq('x', validateParam(' x ', 'string'));
    eq([1], validateParam([1], 'array'));
    eq(null, validateParam('x', 'array'));
    eq(true, validateParam(true, 'bool'));
    eq(null, validateParam('x', 'weird'));

    boot_app();
    checkRouteParams(['id' => '5'], 'id');
    ok(true, 'valid param does not redirect');
    throws(\Cast\Http\HttpException::class, fn() => checkPostParams([], 'no', 'Invoice'), 'Invoice Number Is Required!');
    checkPostParams(['no' => 'x'], 'no', 'Invoice');
});
