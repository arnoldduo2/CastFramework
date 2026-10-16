---
title: Template engine
section: Build
order: 4
description: CastTemplateEngine, installed with the framework - component tags, props, slots, reactive scripts, scoped styles, and the @ shorthand.
---

# Template engine

Every view and component in your app (`*.cast.php`) is compiled by **CastTemplateEngine** (`anode/cast-template-engine`). It comes with the
framework: `composer require anode/cast-framework` installs it, and `Cast\Core\View` is the only code that talks to it. You write views; you
never construct the engine yourself. Its own repository (<https://github.com/arnoldduo2/CastTemplateEngine>) is the full reference; this page is what
you need inside a CastFramework app.

Your templates stay plain PHP: `<?php foreach ?>`, `<?php if ?>` and `<?= ?>` work as they always did. The engine adds:
- **Component tags, children and named slots**
- **Strictly-typed `@props` directive and component inheritance (`extends::...`)**
- **Dynamic expressions as props** (`title={$x}`, `active`)
- **Reactive client scripts** (`@script(...)`, `@js(...)`, `@scripts`) with delegated event handling
- **Scoped component styles** (`@css(...)`, `@styles`) with dynamic CSS custom properties
- **Collection mapping & filtering shorthands** (`@map`, `@filter`, `@$items.filter(...).map(...)`)
- **Inline markup render helpers** (`@render(...)`, `return(...)`)
- **Language Server Protocol (LSP) and Tree-sitter grammar support**
- **A zero-dependency production asset bundler** (`php cast engine:build`)
- **A concise `@` shorthand for native PHP controls**

---

## Component tags

A tag that starts with a capital letter is a component: a plain PHP file in `resources/views/components/`. Dots are folders, and the file name may
be written in four styles (`<Btns.AddNew>` finds `btns/add-new.cast.php`, `add_new`, `AddNew` or `addNew`):

```php
<Card title="Stock summary" total={$total} active>
    <Btns.Button label="Save" variant="primary" />
    <Slot name="footer">Updated <?= date('H:i') ?></Slot>
</Card>
```

| Prop syntax | Value |
| --- | --- |
| `title="Sales"` / `title="Hi <?= $name ?>"` | a string, with PHP echoed inside if you like |
| `total={$total}` / `rows={[1, 2]}` / `on={fn() => 1}` | any PHP expression: numbers, arrays, closures |
| `name={"qty-{$p['qty']}"}` | a string built in PHP |
| `required` | `true` |
| `{...$data}` | spread an array of props |
| `left={<Badge text={$x} />}` | the HTML of another component, also inside ternaries |

Inside the component file, props are accessible as variables (`$title`, `$hasLabel`: `has_label`, `has-label` and `hasLabel` all arrive as `$hasLabel`).
The HTML between the tags is `$children`, and each `<Slot name="footer">` arrives as `$footer`. Defaults are plain PHP (`$title ??= 'KPI';`).
Create a documented component with docblock and defaults:

```bash
php cast make:component Btns.AddNew --props=label:string=Add,href:?string
```

List all components or check docblocks:

```bash
php cast components
php cast components --check
```

See [Components](COMPONENTS.md) for full component reference.

---

## The `@` shorthand for PHP

Native PHP controls can be written using clean directives:

```php
@foreach ($users as $user):
    <p>This is user @{ $user->id }</p>          (@{ } is the same as <?= ?>)
@endforeach

@forelse ($users as $user):
    <li>@{ $user->name }</li>
@empty
    <p>No users</p>
@endforelse

@if ($n > 5): big @elseif ($n > 2): middle @else: small @endif
@for ($i = 0; $i < 3; $i++) @{ $i } @endfor
@while ($row = next($rows)) ... @endwhile
```

| Write | Becomes |
| --- | --- |
| `@{ expr }` | `<?= expr ?>` (printed as it is, like `<?= ?>`: escape user text with `htchars()`) |
| `@for (...):` `@endfor` | `for (...): ... endfor;` |
| `@foreach (...):` `@endforeach` | `foreach (...): ... endforeach;` |
| `@forelse ($list as $item):` `@empty` `@endforelse` | a `foreach` plus an "empty" block for arrays, iterators, and generators |
| `@while (...)` `@endwhile` | `while (...): ... endwhile;` |
| `@if (...)` `@elseif (...)` `@else` `@endif` | `if (...): ... elseif (...): ... else: ... endif;` |
| `@unless (...)` `@endunless` | `if (!(...)):` |
| `@isset ($x)` `@endisset` | `if (isset($x)):` |
| `@switch ($n)` `@case (1)` `@default` `@endswitch` | `switch` statement |
| `@break` `@continue`, or conditional `@break($done)` | `break;` `continue;` `if ($done) break;` |
| `@php($n = 5)` and `@php ... @endphp` | single statement or raw PHP block |
| `@@` | literal `@` character |

---

## Collection mapping & filtering

Render collections with JSX-style declarative mapping:

```php
@map($invoices, ($inv, $i) => return (
    <Card key={$inv->id}>
        <p>@{ $inv->number }: $@{ $inv->amount }</p>
    </Card>
))
```

Or use fluent chaining on arrays and `stdClass` collections:

```php
@$items.filter(($item) => $item->qty > 0).map(($item, $i) => return (
    <TableRow key={$item->id} item={$item} />
))
```

### The `key` and `data-el-key` attribute
Every mapped element or component should declare a unique `key={$val}`:
- On HTML tags: compiled to `data-el-key="{$val}"`.
- On Components: passed as a prop and attached to the root node.
- If omitted, the engine uses `$i` and the compiler/editor issues a diagnostic warning.

---

## Reactive client scripts (`@script`, `@js`)

UI components can include reactive browser logic without needing dedicated standalone JS files:

```php
<div class="product-item" data-id={$id} data-price={$price}>
    <h3>@{ $title }</h3>
    <button class="add-to-cart">Add to Cart</button>
</div>

@script(
    handleEvent('click', '.product-item .add-to-cart', (e, el) => {
        const card = el.closest('.product-item');
        alert('Item added: #' + card.dataset.id + ' ($' + card.dataset.price + ')');
    });
)
```

### Event delegation via `handleEvent` & `Cast.on`
The built-in global helper `handleEvent(event, selector, handler)` attaches delegated event listeners to `document`:
- **Dynamic Elements:** Handlers automatically work on elements injected later via SPA transitions, AJAX, or modal opens without re-binding.
- **Handler Signature:** `(event, matchedElement) => { ... }` where `matchedElement` is the clicked DOM node.
- **Client runtime methods:**
  - `Cast.on(event, selector, handler)`: delegated event listener
  - `Cast.update(target, content)`: updates element/select options in-place
  - `Cast.refresh(target, url)`: swaps an HTML fragment from a URL
  - `Cast.onLoad(fn)`: executes once DOM is ready

### Rendering scripts with `@scripts`
Place `@scripts` right before the closing `</body>` tag of your layout (e.g. `resources/views/layouts/footer.cast.php`). It deduplicates and renders all scripts registered by rendered components along with the runtime.

> **Rule:** Never use PHP `@{ }` inside `@script(...)`. Pass server data via HTML `data-*` attributes and read them in JS with `el.dataset`.

---

## Scoped CSS & dynamic variables (`@css`)

Components can isolate their styles with `@css(...)`:

```php
<div class="user-pill" data-role={$role}>
    <span>@{ $name }</span>
</div>

@css(
    .user-pill {
        padding: 6px 12px;
        border-radius: 999px;
        border: 2px solid @{ $role === 'admin' ? '#0070f3' : '#e0e0e0' };
        background-color: @{ $isActive ? '#f0fdf4' : '#fff' };
    }
    .user-pill span {
        font-weight: 600;
    }
)
```

- **Scoped selectors:** Selectors are scoped under a deterministic component class (`.cast-<id>`).
- **Dynamic variables:** Any `@{ expr }` inside `@css(...)` is transformed into a CSS variable (`var(--cast-<id>-<n>)`), with values injected via an inline `style` attribute on the container. The stylesheet itself remains clean and static.
- **Rendering styles:** Place `@styles` in the `<head>` of your layout (e.g. `resources/views/layouts/header.cast.php`).

---

## Component assets & bundler (`engine:build`)

In addition to inline `@css` and `@script`, components can have dedicated asset files:
- `resources/css/components/<component-name>.css`
- `resources/js/components/<component-name>.js`

The engine discovers and registers these files automatically when a component renders.

### Production bundling
When deploying, bundle and minify all component assets into your main bundles:

```bash
php cast engine:build
```

The bundler:
1. Discovers all component CSS and JS files in `resources/css/components/` and `resources/js/components/`.
2. Strips comments and minifies whitespace (dependency-free).
3. Appends or replaces the compiled code between marker comments in `resources/css/app.css` and `resources/js/app.js`:
   ```css
   /* cast:components:start */
   ...minified component styles...
   /* cast:components:end */
   ```

---

## Console commands

The full, current command list and flags live on [Terminal Commands & CLI](#terminal-commands--cli) — pulled automatically
from CastTemplateEngine's own README each time `php cast docs:build` runs, so it can't drift from what the installed engine
actually supports the way a second, hand-kept copy here would. See also [Documentation Snippets List](#documentation-snippets-list)
for every editor snippet trigger.

---

## How the framework uses it

- **Folders:** views are in `resources/views` (`paths.views`), components in `resources/views/components` (`view.components`), the extension is
  `.cast.php` (`view.ext`, `APP_VIEWS_EXT`). Only files with that extension use the syntax; a plain `.php` file is never touched.
- **Helpers:** `views('items.items', $data)` and `$this->view(...)` in a controller render a page, `__includes('layouts.header', $data)` includes a
  partial, `Component('btns.add-new', $props)` renders a component from PHP, `__modules('app.app', 'css')` links a page's own CSS and JS.
- **Framework pages:** the error, maintenance and module pages are views too. A view with the same name in your `resources/views` wins
  (`errors/404.cast.php`, `errors/module.cast.php`); an empty one means "not built yet" and the framework's page is shown.
- **Cache:** compiled views are written to `storage/framework/views`. In development a changed view is recompiled; in production set
  `view.check_modified` false for a little more speed and run `php cast views:clear` (or `deploy:optimize`) when you deploy.
- **Deploy:** `php cast deploy:optimize` clears compiled views and prepares production assets.
- **Editor:** `php cast editor:install` (alias for `php cast engine:editor install`) adds highlighting, Ctrl+click to a component's file, diagnostics, and prop completion. For the full Language Server + Tree-sitter setup too (richer, PHP-aware intelligence — see [Language Server & Tree-Sitter](#language-server--tree-sitter)), use `php cast engine:editor-install` instead: it runs a requirements check (Node.js, npm, optional Phpactor for PHP-bridging) and installs both the extension and the global `cast-language-server`. `engine:editor-update`/`engine:editor-uninstall` mirror it. All of these are built and maintained by CastTemplateEngine, not CastFramework.

---

## More from the engine

This page is the framework-specific overview; the engine's own README supplies the reference material below as-is
(pulled in automatically by `docs:build`, not copied by hand — `php cast docs:check` fails if they ever drift):

- [Declaring props with @props](#declaring-props-with-props) — strictly-typed props and compile-time validation.
- [Component Inheritance](#component-inheritance) — extending components and inheriting props via `extends::...`.
- [Syntax rules](#syntax-rules) — the full tag/prop/slot/attribute rules, and exactly what the compiler never touches.
- [Escaping](#escaping) — `e()`, and what is and isn't escaped automatically.
- [Caching and errors](#caching-and-errors) — compiled-template caching and how compile/runtime errors are reported.
- [Language Server & Tree-Sitter](#language-server--tree-sitter) — LSP intelligence, diagnostics, and Tree-sitter grammar.
- [Limitations](#limitations) — the sharp edges worth knowing up front.
- [Coming from useComponent()](#coming-from-usecomponent) — migrating an older app's `useComponent()` calls.

## Versions

The framework requires `anode/cast-template-engine` `^2.0.0`. Keep it current with:

```bash
composer update anode/cast-template-engine
```

For full engine source code, tests, and standalone documentation, visit [CastTemplateEngine on GitHub](https://github.com/arnoldduo2/CastTemplateEngine).