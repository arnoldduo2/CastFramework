# Changelog

This project follows [Semantic Versioning](https://semver.org). Until 1.0.0 minor versions may change behaviour.

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
