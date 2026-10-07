"use strict";
/**
 * CastFramework for VS Code: Ctrl+click (and F12, hover) on a component or a view name in a `.cast.php` file opens the file.
 * The syntax colouring is a grammar (syntaxes/), this file is only the navigation. The name-to-file rules live in lib/.
 */
const vscode = require("vscode");
const fs = require("node:fs");
const path = require("node:path");
const { scanComponents, scanHelpers } = require("./lib/scan");
const { resolveComponent, resolveLegacyComponent, resolveView, resolveModule, propsOf } = require("./lib/resolve");

const SELECTOR = { scheme: "file", pattern: "**/*.cast.php" };

/** The folders to search for a document: from the settings, and from config/view.php when it names a components folder. */
function locations(document) {
  const folder = vscode.workspace.getWorkspaceFolder(document.uri);
  const root = folder ? folder.uri.fsPath : path.dirname(document.uri.fsPath);
  const config = vscode.workspace.getConfiguration("cast", document.uri);

  const viewsDir = path.resolve(root, config.get("viewsPath", "resources/views"));
  const resourcesDir = path.resolve(root, config.get("resourcesPath", "resources"));
  const ext = config.get("extension", ".cast.php");

  const configured = [].concat(config.get("componentsPath", "resources/views/components"));
  const dirs = configured.map((p) => path.resolve(root, p));

  // config/view.php: 'components' => 'ui'  (relative to the views folder)
  try {
    const source = fs.readFileSync(path.join(root, "config", "view.php"), "utf8");
    const m = /['"]components['"]\s*=>\s*['"]([^'"]+)['"]/.exec(source);
    if (m) {
      const dir = path.resolve(viewsDir, m[1]);
      if (!dirs.includes(dir)) dirs.unshift(dir);
    }
  } catch {
    /* no config/view.php */
  }
  return { root, viewsDir, resourcesDir, componentDirs: dirs, ext };
}

/** @returns {{range: any, file: string|null, tried: string[], label: string, kind: string}[]} */
function references(document) {
  const text = document.getText();
  const where = locations(document);
  const out = [];

  for (const ref of scanComponents(text)) {
    if (ref.name === "Slot") continue;
    const { file, tried } = resolveComponent(ref.name, where.componentDirs, where.ext);
    out.push({ ...ref, label: ref.name, file, tried });
  }
  for (const ref of scanHelpers(text)) {
    let result;
    if (ref.kind === "view") result = resolveView(ref.name, where.viewsDir, where.ext);
    else if (ref.kind === "legacy-component") result = resolveLegacyComponent(ref.name, where.componentDirs, where.ext);
    else result = resolveModule(ref.name, ref.type, where.resourcesDir);
    out.push({ ...ref, label: ref.name, file: result.file, tried: result.tried });
  }

  return out.map((r) => ({
    ...r,
    range: new vscode.Range(document.positionAt(r.start), document.positionAt(r.end)),
  }));
}

const KIND_TITLE = { component: "Component", "legacy-component": "Component (legacy)", view: "View", module: "Module" };

class Links {
  provideDocumentLinks(document) {
    return references(document)
      .filter((r) => r.file)
      .map((r) => {
        const link = new vscode.DocumentLink(r.range, vscode.Uri.file(r.file));
        link.tooltip = `Open ${path.basename(r.file)} (Ctrl+click)`;
        return link;
      });
  }
}

class Definitions {
  provideDefinition(document, position) {
    const hit = references(document).find((r) => r.file && r.range.contains(position));
    return hit ? new vscode.Location(vscode.Uri.file(hit.file), new vscode.Position(0, 0)) : undefined;
  }
}

class Hovers {
  provideHover(document, position) {
    const hit = references(document).find((r) => r.range.contains(position));
    if (!hit) return undefined;

    const md = new vscode.MarkdownString();
    md.appendMarkdown(`**${KIND_TITLE[hit.kind] || "Cast"}** \`${hit.label}\`\n\n`);
    if (hit.file) {
      md.appendMarkdown(`\`${hit.file}\`\n\n`);
      if (hit.kind === "component" || hit.kind === "legacy-component") {
        try {
          const props = propsOf(fs.readFileSync(hit.file, "utf8"));
          if (props.length) md.appendMarkdown(`Props: ${props.map((p) => "`" + p + "`").join(", ")}\n\n`);
        } catch {
          /* unreadable file: no props */
        }
      }
      md.appendMarkdown("Ctrl+click to open.");
    } else {
      md.appendMarkdown("No file found. Looked for:\n\n" + hit.tried.slice(0, 8).map((t) => "- `" + t + "`").join("\n"));
    }
    return new vscode.Hover(md, hit.range);
  }
}

function activate(context) {
  context.subscriptions.push(
    vscode.languages.registerDocumentLinkProvider(SELECTOR, new Links()),
    vscode.languages.registerDefinitionProvider(SELECTOR, new Definitions()),
    vscode.languages.registerHoverProvider(SELECTOR, new Hovers())
  );
}

function deactivate() {}

module.exports = { activate, deactivate, references, locations };
