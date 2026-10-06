# Changelog

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 minor versions may change behaviour.

## 0.2.0

SPA layer and JSON API.

- **SPA:** `View::respond()` decides full page, partial or modal. Pages opt in with `'spa' => true`; the first load is the layout with a skeleton (`spa.initial` = `lazy`) or the real
  content (`inline`); Cast requests (`X-Cast-Request`) get a JSON envelope `{type, target, title, html, css, js, own, guard, url, page, modalClass, form, csrf}`, a redirect envelope or a reload.
  Fragments and modals with `'fragment' => true` / `'type' => 'modal'`. `views()` follows the same rules. New helper `__cast()`.
- **Client** (`/cast/cast.module.js`, `/cast/cast.css`, served from the package): link and form handling, `Cast.load`, `Cast.http`, `Cast.page({mount, destroy})` lifecycle with delegated listeners,
  asset loading and removal, `cast:*` events, history, modal, 422 field errors, `Cast.configure({http, modal})`.
- **API:** `routes/api.php` under `api.prefix`; always-JSON errors for the prefix (401, 403, 404, 405, 419, 422, 429, 500); `ApiAuth` middleware; hashed bearer tokens with abilities, expiry and revocation
  (`ApiTokens`, `TokenStore`, `DatabaseTokenStore`, `ApiTokenSchema`, `FindsUsersById`); `Throttle` rate limiting with `X-RateLimit-*`; CSRF is not required for bearer-token or cookie-less API requests;
  CORS exposes the rate-limit headers; `token:create`, `token:revoke`, `token:schema`.
- `Request`: `isApi()`, `bearerToken()`, `user()`, `token()`, `addResponseHeader()`; a hand-built request parses the query string of its URI; `castType()` defaults to `partial`.
- `Auth::verify()` (check credentials without a session) and `Auth::provider()`.
- Starter: SPA pages (login, items with an edit modal, stats) and a JSON API (`/api/auth/token`, `/api/me`, `/api/items`).
- Tests: server-side suites for both, and Playwright browser checks (`tests/e2e/spa.e2e.js`).

## 0.1.0

First release.

- `Application` (paths, container, providers), `Env`, `Config` (app files merge over framework defaults).
- `Router` with GET, POST, PUT, PATCH, DELETE, `match`, `any`, groups, middleware specs, permission slugs, 404 and 405.
- `Request` (JSON and form bodies for every verb, method override) and `Response` (`{status, msg, data}` helpers).
- Global CSRF token, checked for POST, PUT, PATCH and DELETE.
- Validation: string and object rules, custom rules, legacy pipe syntax, 422 JSON or flash-and-redirect.
- `Auth`, `PasswordPolicy`, `ModelPersister`, `SessionGuard`, `Authenticate`, contracts for extension points.
- `Model` and a bound, validated `QueryBuilder`; `Database` for MySQL, PostgreSQL and SQLite.
- `View` on CastTemplateEngine with auto-loaded page CSS and JS, legacy components, shared data.
- Static file serving with traversal protection, security headers, CORS for exact origins.
- Error pages, maintenance mode (file store), update hook.
- Console: `serve`, `route:list`, `views:clear`, `down`, `up`, `env:check`, `version`, `make:*`.
- Helpers by category with an app `custom` folder; `starter/` app.
