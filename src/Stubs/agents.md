# Notes for AI coding tools

This app is built on CastFramework (`anode/cast-framework`).

## Components

- Component files live in `resources/views/components`. `<Btns.Button />` is `components/btns/button.cast.php`; dots are folders.
- **Read a component's docblock before using it.** Every `@var type $name description` line is a prop, with its type and meaning.
- Props reach the file as camelCase variables: `has-label`, `has_label` and `hasLabel` all become `$hasLabel`.
- The content between the tags is `$children`. `<Slot name="footer">` content arrives as the prop `$footer`.
- `php cast components` lists every component; `php cast components --json` gives a machine-readable index; `php cast components Btns.Button` shows one in full.
- New components: `php cast make:component Btns.Button --props=label:string=Button,href:?string`. Keep the docblock in step with the code (`php cast components --check`).

## Documentation

- The framework's documentation is a site at `/docs` while the dev server runs (`php cast serve`). Pages are markdown files with front matter (title, section, order, description); build with `php cast docs:build` (see docs/WRITING-DOCS.md in the framework).
- When you change behaviour, update the matching `.md` page and rebuild, so the docs, the `--help` text and the code agree.

## Commands

- `php cast list` shows all commands. `php cast serve` starts the app.
- Database: `php cast make:migration`, `php cast migrate`, `php cast migrate:status`.
- Pages return `views('folder.page', $data)` from controllers; add `'spa' => true` to `$data` for in-page navigation.
