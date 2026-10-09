---
title: Writing the docs
section: Tooling
order: 5
description: How the documentation site at /docs is made from markdown files, and how to build one for your own project.
---

# Writing the docs

The documentation you are reading is a static site made from markdown files. There is no build tool: a PHP command turns the `.md` files into one data file (`data.js`) and a viewer page (`index.html`) shows it. Edit the markdown, run the command, reload `/docs`.

```bash
php cast serve                      # then open http://127.0.0.1:8000/docs
composer docs                       # in the framework repository: rebuilds src/Resources/docs/data.js
php cast docs:build                 # in your app: docs/*.md -> public/docs/ (data.js + index.html)
php cast docs:build --check         # exit 1 when data.js is older than the markdown (for CI)
```

## One page = one markdown file

A page is a `.md` file in the docs folder. It may start with front matter, five lines between `---` fences:

```markdown
---
title: Getting started
section: Start
order: 1
description: One sentence shown under the title and in search results.
---

# Getting started

Text, **bold**, `code`, [links](other-page.md#a-heading), tables, lists and fenced code blocks.
```

| Key | Meaning |
| --- | --- |
| `title` | The page title (default: the first `# heading`, or the file name) |
| `section` | The group in the sidebar (default: `Reference`) |
| `order` | The position inside its section, smallest first (default 50) |
| `description` | A one-line summary under the title, also searched |
| `draft: true` | Leave the page out |

The first `# heading` is dropped from the body because the viewer shows the title itself. Write `##` and `###` headings after it: they become the "On this page" list.

## Sections and a README

`docs/_meta.json` sets the site title, the order of the sections, and how a README is split into pages (one page per `## heading`):

```json
{
  "title": "My project",
  "sections": ["Start", "Build", "Reference"],
  "readme": { "Install": ["Start", 1], "Routing": ["Build", 1], "Contents": ["_skip", 0] }
}
```

Build with the README: `php cast docs:build --source=docs --readme=README.md --out=public/docs`. A README heading that is not listed goes to `Reference`; `"_skip"` leaves one out.

## Links

Link between pages the way you would on GitHub: `[text](other-page.md)`, `[text](other-page.md#heading)`, `[text](../README.md#heading)` or `[text](#heading)`. The builder rewrites them to the viewer's addresses and every heading anchor finds its page. A test fails when a link points to a page that does not exist.
Inside a table cell write a pipe that belongs to code as `\|`.

## Showing the site

The framework's own documentation is served at `/docs` while `APP_ENV` is not `production` (`config/docs.php`: `'enabled' => true` also serves it in production). To show your project's docs there instead, build them to `public/docs` and point the `docs` entry of `config/static.php` at that folder: `'docs' => ['dir' => 'public/docs', 'keep_prefix' => false, 'index' => 'index.html']`.
The viewer works from files too (open `index.html` in a browser), with search (press `/`), a dark and a light theme, copy buttons on code blocks and a list of the headings of the page. Its look is the CSS variables at the top of `index.html`.
