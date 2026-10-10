---
title: Template engine
section: Build
order: 4
description: CastTemplateEngine, installed with the framework - component tags, props, slots and the @ shorthand for PHP in your views.
---

# Template engine

Every view and component in your app (`*.cast.php`) is compiled by **CastTemplateEngine** (`anode/cast-template-engine`). It comes with the
framework: `composer require anode/cast-framework` installs it, and `Cast\Core\View` is the only code that talks to it. You write views; you
never construct the engine yourself. Its own README (<https://github.com/arnoldduo2/CastTemplateEngine>) is the full reference; this page is what
you need inside a CastFramework app. Your templates stay plain PHP: `<?php foreach ?>`, `<?php if ?>` and `<?= ?>` work as they always did, and
the engine adds component tags, props, slots and a shorter way to write PHP.

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
| `total={$total}` / `rows={[1, 2]}` / `on={fn() => 1}` | any PHP expression |
| `name={"qty-{$p['qty']}"}` | a string built in PHP |
| `required` | `true` |
| `{...$data}` | spread an array of props |
| `left={<Badge text={$x} />}` | the HTML of another component, also inside ternaries |

Inside the component file the props are variables (`$title`, `$hasLabel`: `has_label`, `has-label` and `hasLabel` all arrive as `$hasLabel`), the
HTML between the tags is `$children`, and each `<Slot name="footer">` arrives as `$footer`. Defaults are plain PHP (`$title ??= 'KPI';`).
`php cast make:component Btns.AddNew --props=label:string` writes a documented one, and `php cast components` lists them all
([Components](COMPONENTS.md)).

## The `@` shorthand for PHP (engine 1.1.0 and newer)

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

Also `@unless`, `@isset`, `@switch` / `@case` / `@default`, `@break`, `@continue` (each with an optional condition: `@continue($i === 2)`) and
`@php($n = 5)` or `@php ... @endphp`. `@{ }` prints as it is, exactly like `<?= ?>`: escape user text with `htchars()`. The colon after the
parentheses is optional. `@@` is a literal `@`; e-mail addresses, CSS rules such as `@media` and `<?php ?>` blocks are left alone. A directive never
adds or removes a line, so an error still points at the right line of your view; a mistake (`@endfor` without `@for`, a `@foreach` that is never
closed) is reported with the view and its line. Check the version you have with `composer show anode/cast-template-engine`; update with
`composer update anode/cast-template-engine`.

## How the framework uses it

- **Folders:** views are in `resources/views` (`paths.views`), components in `resources/views/components` (`view.components`), the extension is
  `.cast.php` (`view.ext`, `APP_VIEWS_EXT`). Only files with that extension use the syntax; a plain `.php` file is never touched.
- **Helpers:** `views('items.items', $data)` and `$this->view(...)` in a controller render a page, `__includes('layouts.header', $data)` includes a
  partial, `Component('btns.add-new', $props)` renders a component from PHP, `__modules('app.app', 'css')` links a page's own CSS and JS.
- **Framework pages:** the error, maintenance and module pages are views too. A view with the same name in your `resources/views` wins
  (`errors/404.cast.php`, `errors/module.cast.php`); an empty one means "not built yet" and the framework's page is shown.
- **Cache:** compiled views are written to `storage/framework/views`. In development a changed view is recompiled; in production set
  `view.check_modified` false for a little more speed and run `php cast views:clear` (or `deploy:optimize`) when you deploy. The folder must not be
  served by the web server: compiled views are PHP code.
- **Errors:** an error inside a view is reported at your view and its line, not the compiled copy (engine 1.0.3 and newer). A component tag with
  no file is a compile error listing every path tried. Common mistake: a view that starts with `declare(strict_types=1);` (`php cast views:check --fix`).
- **Editor:** `php cast editor:install` adds highlighting, Ctrl+click to a component's file and prop completion for `.cast.php` files.

## Versions

The framework asks for `anode/cast-template-engine` `^1.0.2` and `php cast env:check` tells you when yours is old. Keep it current with
`composer update anode/cast-template-engine anode/error-handler` ([Upgrading](UPGRADING.md)).
