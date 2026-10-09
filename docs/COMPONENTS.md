---
title: Documenting components
section: Build
order: 4
description: Describe a component's props in a docblock so the editor, the CLI and AI tools can use it.
---

# Documenting components

A component file describes itself in a docblock. The VS Code extension, `php cast components` and AI coding tools all read the same thing.

```php
<?php
/**
 * Button component
 * @var string|null $label The text to display on the button
 * @var string|null $type The button type (e.g., "button", "submit", "reset")
 * @var string|null $href The URL to link to (if provided, renders an <a> tag instead of a <button>)
 * @var string|null $variant The button variant (e.g., "primary", "secondary", "ghost")
 */
$label ??= 'Button';
$variant ??= 'primary';
```

## What is read

| Part | Meaning |
| --- | --- |
| first text in the docblock | the component's description |
| `@var`, `@param`, `@prop`, `@property` `type $name description` | a prop; the description may continue on the next lines |
| `$name ??= value;` | the default; the prop is optional |
| `@slot name description` | a named slot (`<Slot name="name">`) |
| `@example <Btns.Button label="Save" />` | an example (may span lines) |
| `@deprecated reason` | on the docblock: the component; on a prop line: that prop |

Names that the code uses with `??=` but that have no `@var` line are listed as *inferred* (no type, no description).

## Required, optional, allowed values

- **Optional**: the type contains `null` or `mixed`, the prop has a `??=` default, the description contains `[optional]`, or it is a `bool`. Anything else documented is **required**.
- **Allowed values** come from literal types (`'primary'|'ghost'`) and from lists in the description: `e.g., "a", "b"`, `one of a, b`, or a parenthesised list of quoted words.
- Props are camelCase in the file (`$hasLabel`); `has-label`, `has_label` and `hasLabel` all reach it. The extension inserts the style set in `cast.propCase`.

## In the editor

Hover on a tag shows the table. Inside an open tag you get the props not used yet (required first), values after `=`, slot names inside `<Slot name="">`, and component names after `<`. Ctrl+click on a prop name jumps to its `@var` line.
Problems (unknown prop, missing required prop, value outside the allowed list, deprecated use) are reported only for components that document their props; `cast.diagnostics` is `hint` by default, `warning` or `off` as you like. Quick fixes add missing props or correct near-miss names.

## On the command line

```
php cast components                       table of all components
php cast components Btns.Button           one component in full
php cast components --json                machine-readable index for tools and agents
php cast components --markdown --write=docs/components.md
php cast components --check               exit 1 when docs and code disagree (for CI)
php cast make:component Btns.AddNew --props=label:string=Add,href:?string,block:bool
```

`--check` reports: no description, a documented prop the file never uses, a `??=` default without a `@var` line, and props that are not documented at all.

`php cast init` writes an `AGENTS.md` (only when you do not have one) that tells AI tools to read a component's docblock first and where the index is.
