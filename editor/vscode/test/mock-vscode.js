"use strict";
// Just enough of the VS Code API for extension.js: the providers are captured so the tests can call them.
const path = require("node:path");

class Position {
  constructor(line, character) {
    this.line = line;
    this.character = character;
  }
}
class Range {
  constructor(start, end) {
    this.start = start;
    this.end = end;
  }
  contains(p) {
    const after = (a, b) => a.line > b.line || (a.line === b.line && a.character >= b.character);
    return after(p, this.start) && after(this.end, p);
  }
}
class Uri {
  constructor(fsPath) {
    this.fsPath = fsPath;
  }
  static file(p) {
    return new Uri(p);
  }
}
class Location {
  constructor(uri, position) {
    this.uri = uri;
    this.position = position;
  }
}
class DocumentLink {
  constructor(range, target) {
    this.range = range;
    this.target = target;
  }
}
class MarkdownString {
  constructor() {
    this.value = "";
  }
  appendMarkdown(s) {
    this.value += s;
    return this;
  }
}
class Hover {
  constructor(contents, range) {
    this.contents = contents;
    this.range = range;
  }
}

function create({ root, settings = {} }) {
  const providers = {};
  const api = {
    Position,
    Range,
    Uri,
    Location,
    DocumentLink,
    MarkdownString,
    Hover,
    providers,
    languages: {
      registerDocumentLinkProvider: (selector, p) => ((providers.links = p), { dispose() {} }),
      registerDefinitionProvider: (selector, p) => ((providers.definition = p), { dispose() {} }),
      registerHoverProvider: (selector, p) => ((providers.hover = p), { dispose() {} }),
    },
    workspace: {
      getWorkspaceFolder: () => (root ? { uri: Uri.file(root) } : undefined),
      getConfiguration: () => ({ get: (key, fallback) => (key in settings ? settings[key] : fallback) }),
    },
  };
  return api;
}

/** A TextDocument for `text` at `file`. */
function document(file, text) {
  const lines = text.split("\n");
  const starts = [];
  let offset = 0;
  for (const l of lines) {
    starts.push(offset);
    offset += l.length + 1;
  }
  const positionAt = (o) => {
    let line = 0;
    while (line + 1 < starts.length && starts[line + 1] <= o) line++;
    return new Position(line, o - starts[line]);
  };
  return { uri: Uri.file(file), getText: () => text, positionAt, offsetAt: (p) => starts[p.line] + p.character };
}

module.exports = { create, document, Position, path };
