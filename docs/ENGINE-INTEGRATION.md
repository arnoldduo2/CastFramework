---
title: Engine integration notes
draft: true
---

# Engine integration notes

Working notes on the contract between CastFramework (`anode/cast-framework`) and CastTemplateEngine
(`anode/cast-template-engine`, checked out as a sibling at `../CastTemplateEngine` in this dev setup). Not
published to the docs site (`draft: true`) — this is for whoever next works in either repo, not app authors.

## The principle

CastTemplateEngine owns the `.cast.php` syntax, so it owns everything that *teaches* that syntax to a tool:
the editor extension, its grammars/snippets, and (as of this change) the canonical CLI/reference docs for it.
CastFramework references that content instead of keeping a second, hand-maintained copy — every duplicate
copy is a place the two can silently drift, which is exactly what had already happened to the editor extension
(CastFramework's `editor/vscode` was stuck at v1.5.0 while the engine's own copy had moved to v1.5.1).

Both packages must still work **alone**: CastFramework without the engine's newer features degrades gracefully
rather than crashing, and CastTemplateEngine is fully usable standalone (it has its own `bin/cast` entry point,
independent of CastFramework's). Paired, they get the full feature set.

## What changed on the CastFramework side (this change)

- `editor:install` is now a thin alias for `engine:editor install` (`src/Console/Commands/EditorInstallCommand.php`).
  It no longer carries its own copy/remove/home-detection logic or its own `editor/vscode` folder — both were
  deleted. `--dir=PATH` is gone (the engine's `EngineEditorCommand` has no equivalent); `--editor=NAME` and
  `--uninstall` still work.
- `EngineCommand::handle()` (`src/Console/Commands/EngineCommand.php`) now forwards an `Input`'s options (not
  just its positional arguments) to whatever engine command it calls. Previously `php cast engine:editor install
  --editor=code` silently dropped `--editor=code` before it ever reached the engine — this affected direct
  `engine:editor` usage too, not just the new alias.
- `docs:build` (`src/Console/Commands/DocsBuildCommand.php`, `src/Support/DocsBuilder.php`) now also reads the
  installed engine's own `README.md` and pulls specific, explicitly-listed sections into this site (`docs/_meta.json`'s
  `"engineReadme"` map) instead of CastFramework hand-paraphrasing them. `php cast docs:check` is therefore now
  the one command that verifies both packages' docs are still in sync — run it whenever either repo's README changes.
- The `editor` CI job in `.github/workflows/tests.yml` (which ran the extension's own JS tests and built a
  `.vsix` from CastFramework's `editor/vscode`) was removed — there's nothing left here for it to test. If
  CastTemplateEngine's own CI doesn't already have an equivalent job for its `editor/vscode`, it needs one.

## What's still needed on the CastTemplateEngine side (not done here — out of scope for this change)

1. **Nothing is required** for the above to work as shipped: `EngineEditorCommand::execute()` already accepts
   `install`/`update`/`uninstall` plus `--editor=NAME`, already ignores array entries it doesn't recognize (so
   forwarding extra/unknown flags from CastFramework is safe), and already reads `getenv('USERPROFILE') ?:
   getenv('HOME')` for the install target — which is enough for CastFramework's own tests to redirect installs
   into a scratch directory for isolation (set `USERPROFILE`/`HOME`, no engine change needed).
2. **Optional, non-blocking enhancement**: add a `CAST_HOME` env-var fallback to `EngineEditorCommand`'s home
   detection, checked *before* `USERPROFILE`/`HOME` — this matches the convention CastFramework's old, now-deleted
   `EditorInstallCommand::home()` used, and gives power users who run multiple tool-homes one more option. Nothing
   in CastFramework depends on this; it's a nice-to-have for parity, not a requirement.
3. **Keep the compatibility contract**: `EngineEditorCommand::execute(array $args)` (and any future `engine:*`
   command) should keep silently ignoring array entries it doesn't understand, the way it does today, rather than
   erroring on an unrecognized flag — that's what lets CastFramework forward its own options without needing to
   know in advance which engine version is installed. Symmetrically, `CastFramework\Console\Commands\EngineCommand`
   already fails soft (one clear "could not be resolved" message, exit 1) when `CastTemplateEngine\Console\
   CommandRegistry` can't be found at all — keep that the only failure path when pairing an old CastFramework with
   a newer/older engine, or vice versa.
4. **If CastTemplateEngine's README headings change**: `docs/_meta.json`'s `"engineReadme"` map in CastFramework
   lists every current top-level `## heading` of the engine's README explicitly, each either placed under a
   section or marked `"_skip"`. A heading not in that map is now skipped by default (opt-in placement — see
   `DocsBuilder::splitReadme()`'s `$skipUnlisted` parameter), so a new or renamed heading in the engine's README
   won't silently appear in CastFramework's docs unreviewed; it just won't show up until someone adds it to the
   map. `php cast docs:check` in CastFramework will keep passing either way — it only catches *content* drift
   in headings it already pulls, not new headings it doesn't yet know about — so renaming a pulled heading
   (e.g. "Terminal Commands & CLI") is the thing to watch for, since the old key would then match nothing and
   that page would silently disappear from CastFramework's docs next build.
