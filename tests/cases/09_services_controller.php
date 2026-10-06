<?php

declare(strict_types=1);

use Cast\Contracts\Guard;
use Cast\Contracts\HasLineItems;
use Cast\Contracts\Patchable;
use Cast\Contracts\Savable;
use Cast\Contracts\UserProvider;
use Cast\Core\Router;
use Cast\Core\Session;
use Cast\Http\Controller;
use Cast\Http\HttpException;
use Cast\Http\Middleware\Authenticate;
use Cast\Http\Request;
use Cast\Http\Response;
use Cast\Services\Auth;
use Cast\Services\DuplicateEntryException;
use Cast\Services\ModelPersister;
use Cast\Services\PasswordPolicy;
use Cast\Services\SessionGuard;

class FakeUsers implements UserProvider
{
    public array $loggedIn = [];
    public function __construct(private array $rows = []) {}
    public function findByCredentials(string $identifier): ?array { return $this->rows[$identifier] ?? null; }
    public function onLogin(array $user): void { $this->loggedIn[] = $user['email']; }
    public function passwordKey(): string { return 'password'; }
}

function fake_users(): FakeUsers
{
    return new FakeUsers([
        'ann@x.com' => ['id' => 1, 'email' => 'ann@x.com', 'password' => Auth::hash('Secret123'), 'permissions' => ['view-reports']],
        'old@x.com' => ['id' => 2, 'email' => 'old@x.com', 'password' => crypt('legacy-pass', '$2a$10$' . str_repeat('a', 21) . 'a')],
    ]);
}

test('PasswordPolicy: default rules and readable messages', function () {
    eq('', PasswordPolicy::check('Secret123'));
    eq('Password must have at least 8 minimum characters', PasswordPolicy::check('Ab1'), 'length only');
    eq('Password must have at least 1 uppercase letter', PasswordPolicy::check('secret123'));
    eq('Password must have at least 1 lowercase letter', PasswordPolicy::check('SECRET123'));
    eq('Password must have at least 1 number', PasswordPolicy::check('SecretSecret'));
    eq('Password must have at least 8 minimum characters, 1 lowercase letter, 1 uppercase letter, 1 number', PasswordPolicy::check(''));
    eq('', PasswordPolicy::check('anything', [], enforce: false), 'enforcement is the caller’s decision');
    eq('Password must have at least 1 special character', PasswordPolicy::check('Secret123', ['sp' => 1]));
    eq('', PasswordPolicy::check('Secret123!', ['sp' => 1, 'len' => 10]));
    eq('', PasswordPolicy::check('abc', ['len' => 3, 'lc' => 0, 'uc' => 0, 'nums' => 0]));
});

test('Auth: attempt() logs in, removes the hash from the session, rotates the CSRF token, calls onLogin', function () {
    boot_app();
    $users = fake_users();
    $auth = new Auth($users);
    $before = Session::csrfToken();

    ok($auth->attempt('ann@x.com', 'Secret123'));
    ok($auth->check());
    eq('ann@x.com', $auth->user()['email']);
    ok(!isset($auth->user()['password']), 'hash never stored in the session');
    ok(!isset($_SESSION['login']['password']));
    ok(Session::csrfToken() !== $before, 'CSRF token rotated on login');
    eq(['ann@x.com'], $users->loggedIn);
});

test('Auth: wrong password, unknown user and malformed rows fail without side effects', function () {
    boot_app();
    $users = fake_users();
    $auth = new Auth($users);
    ok(!$auth->attempt('ann@x.com', 'wrong'));
    ok(!$auth->attempt('nobody@x.com', 'Secret123'));
    ok(!$auth->attempt('ann@x.com', ''));
    ok(!$auth->check());
    eq([], $users->loggedIn);
    $broken = new Auth(new FakeUsers(['b@x.com' => ['email' => 'b@x.com']]));
    ok(!$broken->attempt('b@x.com', 'x'), 'user row without a password hash');
});

test('Auth: legacy $2a$ bcrypt hashes verify, logout clears the user, hash helpers', function () {
    boot_app();
    $auth = new Auth(fake_users());
    ok($auth->attempt('old@x.com', 'legacy-pass'), 'hashes from the old app still work');
    $auth->logout();
    ok(!$auth->check() && $auth->user() === null);

    $hash = Auth::hash('pw');
    ok(password_verify('pw', $hash) && !Auth::needsRehash($hash));
    ok(Auth::needsRehash('$2y$04$' . str_repeat('a', 53)), 'weak cost is flagged');
    ok(verifyPassword('pw', hashPassword('pw')));
});

test('Auth: the session key is configurable', function () {
    boot_app(['config/auth.php' => "<?php return ['session_key' => 'member'];"]);
    $auth = new Auth(fake_users());
    $auth->attempt('ann@x.com', 'Secret123');
    ok(isset($_SESSION['member']) && !isset($_SESSION['login']));
});

test('SessionGuard: check() for private/auth/public guards, can() with permission slugs, user()', function () {
    boot_app();
    $guard = new SessionGuard();
    ok(!$guard->check('private') && $guard->check('auth') && $guard->check('public'));
    ok(!$guard->can('anything') && $guard->user() === null);

    $_SESSION['login'] = ['id' => 1, 'permissions' => ['view-reports', 'edit-x']];
    ok($guard->check('private') && !$guard->check('auth') && $guard->check('public'));
    ok($guard->can('view-reports') && $guard->can(['nope', 'edit-x']) && !$guard->can('delete-x') && !$guard->can([]));
    eq(1, $guard->user()['id']);
    eq(1, __getUser('id'));
    eq(null, __getUser('missing'));
    eq('x', (function () { $_SESSION['login'] = ['name' => 'x']; return __getUser('name'); })());
});

test('Authenticate middleware: private pages redirect guests to login; ajax gets 401; auth pages redirect users home', function () {
    boot_app(['config/app.php' => "<?php return ['base_path' => '/erp'];", 'config/auth.php' => "<?php return ['login_path' => '/login', 'home_path' => '/dashboard'];"]);
    Router::middleware([Authenticate::class, 'private'], fn() => Router::get('/dash', fn() => 'dash'));
    Router::middleware([Authenticate::class, 'auth'], fn() => Router::get('/login', fn() => 'login-form'));
    Router::middleware([Authenticate::class, 'public'], fn() => Router::get('/about', fn() => 'about'));
    Router::middleware([Authenticate::class], fn() => Router::get('/default-private', fn() => 'x'));

    $r = handle(req('GET', '/dash'));
    eq(302, $r->statusCode());
    eq('/erp/login', $r->getHeader('Location'));
    eq('login-form', handle(req('GET', '/login'))->body());
    eq('about', handle(req('GET', '/about'))->body());
    eq(302, handle(req('GET', '/default-private'))->statusCode(), 'private is the default guard');

    $ajax = handle(req('GET', '/dash', [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
    eq(401, $ajax->statusCode());
    eq('error', $ajax->data()['status']);

    $_SESSION['login'] = ['id' => 1];
    eq('dash', handle(req('GET', '/dash'))->body());
    $r = handle(req('GET', '/login'));
    eq(302, $r->statusCode());
    eq('/erp/dashboard', $r->getHeader('Location'), 'signed-in users do not see the login page');
});

test('Authenticate middleware: a Guard must be bound', function () {
    $app = boot_app();
    Router::middleware([Authenticate::class, 'private'], fn() => Router::get('/x', fn() => 'x'));
    $app->bind('guard', fn() => new stdClass());
    throws(RuntimeException::class, fn() => handle(req('GET', '/x')), 'No Guard');
});

test('An app can bind its own Guard (permissions, route slugs and $this->authorize() all use it)', function () {
    $app = boot_app();
    $guard = new class implements Guard {
        public function check(string $guard): bool { return true; }
        public function can(string|array $permission): bool { return in_array('ok', (array) $permission, true); }
        public function user(): ?array { return ['id' => 9]; }
    };
    $app->set('guard', $guard);
    Router::get('/p', fn() => 'p')->middleware(['ok']);
    Router::get('/q', fn() => 'q')->middleware(['no']);
    eq('p', handle(req('GET', '/p'))->body());
    eq(403, handle(req('GET', '/q'))->statusCode());

    $c = new class extends Controller {
        public function check(string $p): string { $this->authorize($p); return 'allowed'; }
    };
    eq('allowed', $c->check('ok'));
    throws(HttpException::class, fn() => $c->check('no'), 'required permission');
    ok(_checkAccess('ok') === null);
    throws(HttpException::class, fn() => _checkAccess(['a', 'b']));
});

// ----------------------------------------------------------------- ModelPersister

class PersistHeader implements Savable, Patchable, HasLineItems
{
    public static array $log = [];
    public static mixed $saveResult = 7;
    public function save(array $data): int|string|false { self::$log[] = 'save'; return self::$saveResult; }
    public function patch(array $data): int|string|false { self::$log[] = 'patch'; return self::$saveResult; }
    public function saveLineItems(int|string $parentId, array $data): void { self::$log[] = "lines-save:$parentId"; }
    public function patchLineItems(int|string $parentId, array $data): void { self::$log[] = "lines-patch:$parentId"; }
}
class PersistPlain implements Savable
{
    public function save(array $data): int|string|false { return 'INV-1'; }
}

test('ModelPersister: save() stores the header then its line items, then runs the callback', function () {
    PersistHeader::$log = [];
    PersistHeader::$saveResult = 7;
    $seen = null;
    $id = (new ModelPersister())->save(PersistHeader::class, ['a' => 1], function ($data, $id) use (&$seen) { $seen = [$data, $id]; PersistHeader::$log[] = 'after'; });
    eq(7, $id);
    eq(['save', 'lines-save:7', 'after'], PersistHeader::$log);
    eq([['a' => 1], 7], $seen);
    eq('INV-1', (new ModelPersister())->save(new PersistPlain(), []), 'string numbers and instances work; no line items needed');
});

test('ModelPersister: patch() mirrors save(); failures and duplicates throw', function () {
    PersistHeader::$log = [];
    PersistHeader::$saveResult = 3;
    eq(3, (new ModelPersister())->patch(PersistHeader::class, ['a' => 1]));
    eq(['patch', 'lines-patch:3'], PersistHeader::$log);

    PersistHeader::$saveResult = false;
    throws(RuntimeException::class, fn() => (new ModelPersister())->save(PersistHeader::class, []), 'Error saving data');
    throws(RuntimeException::class, fn() => (new ModelPersister())->patch(PersistHeader::class, []), 'Error saving changes');

    PersistHeader::$saveResult = 'duplicate';
    throws(DuplicateEntryException::class, fn() => (new ModelPersister())->save(PersistHeader::class, []), 'already been saved');
    eq(409, (new DuplicateEntryException())->getCode());
});

test('ModelPersister: the model must implement the contract; line items are skipped when the save fails', function () {
    throws(InvalidArgumentException::class, fn() => (new ModelPersister())->save(stdClass::class, []), 'must implement');
    throws(InvalidArgumentException::class, fn() => (new ModelPersister())->patch(new PersistPlain(), []), 'Patchable');

    PersistHeader::$log = [];
    PersistHeader::$saveResult = false;
    try { (new ModelPersister())->save(PersistHeader::class, []); } catch (RuntimeException) {}
    eq(['save'], PersistHeader::$log, 'no line items for a failed save');
});

// ---------------------------------------------------------------------- Controller

eval('namespace ModelsNs; class Customers {} class Products {}');

test('Controller: $this->request is the current request without calling the constructor', function () {
    boot_app();
    Request::setCurrent(req('POST', '/x', ['a' => 1]));
    $c = new class extends Controller {
        public function __construct() {} // does not call parent
        public function read(): mixed { return $this->request->input('a'); }
        public function missing(): mixed { return $this->nothing; }
    };
    eq(1, $c->read());
    throws(Exception::class, fn() => $c->missing(), 'Undefined property');
});

test('Controller: success(), error(), redirect() build R11 responses', function () {
    boot_app(['config/app.php' => "<?php return ['base_path' => '/erp'];"]);
    $c = new class extends Controller {
        public function ok(): Response { return $this->success('Saved', ['id' => 1], 201); }
        public function bad(): Response { return $this->error('Nope', 409, ['f' => 'x']); }
        public function go(): Response { return $this->redirect('/list'); }
    };
    $r = $c->ok();
    eq(201, $r->statusCode());
    eq(['status' => 'success', 'msg' => 'Saved', 'data' => ['id' => 1]], $r->data());
    eq(['status' => 'error', 'msg' => 'Nope', 'data' => ['errors' => ['f' => 'x']]], $c->bad()->data());
    eq('/erp/list', $c->go()->getHeader('Location'));
});

test('Controller: getModelInstance() finds registered, fully-qualified and namespaced models (cached)', function () {
    boot_app(['config/models.php' => "<?php return ['namespace' => 'ModelsNs'];"]);
    $c = new class extends Controller {
        public function find(string $m): ?object { return $this->getModelInstance($m); }
    };
    ok($c->find('ModelsNs\\Customers') instanceof ModelsNs\Customers, 'full class name');
    $products = $c->find('products');
    ok($products instanceof ModelsNs\Products, 'name + trailing s');
    ok($c->find('products') === $products, 'cached');
    eq(null, $c->find('ghost'));

    $withModel = new class(ModelsNs\Customers::class) extends Controller {
        public function registered(): ?object { return $this->getModelInstance(ModelsNs\Customers::class); }
    };
    ok($withModel->registered() instanceof ModelsNs\Customers);
});

test('Controller: a full stack: controller + validation + CSRF + JSON verbs through the kernel', function () {
    boot_app();
    $_SESSION['_csrf_token'] = 'tok';
    $controller = new class extends Controller {
        public function store(): Response { return $this->success('Created', $this->validate(['name' => 'required|min:2']), 201); }
        public function update(string $id): Response { return $this->success('Updated', ['id' => $id] + $this->request->only('name')); }
        public function destroy(string $id): Response { return $this->success('Deleted', ['id' => $id]); }
    };
    $class = $controller::class;
    Router::post('/things', [$class, 'store']);
    Router::put('/things/{id}', [$class, 'update']);
    Router::delete('/things/{id}', [$class, 'destroy']);
    $h = ['HTTP_X_CSRF_TOKEN' => 'tok'];

    $r = handle(req('POST', '/things', ['name' => 'Ann'], $h));
    eq(201, $r->statusCode());
    eq('Ann', $r->data()['data']['name']);
    eq(422, handle(req('POST', '/things', ['name' => 'A'], $h + ['HTTP_ACCEPT' => 'application/json']))->statusCode());
    eq(['id' => '5', 'name' => 'Bo'], handle(req('PUT', '/things/5', ['name' => 'Bo'], $h))->data()['data']);
    eq('Deleted', handle(req('DELETE', '/things/5', [], $h))->data()['msg']);
    eq(419, handle(req('DELETE', '/things/5'))->statusCode());
});
