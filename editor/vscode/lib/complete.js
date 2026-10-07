"use strict";
/**
 * Completion contexts and diagnostics for component tags: pure functions on text and specs, no VS Code API.
 */
const { scanComponents } = require("./scan");
const { camel } = require("./docblock");
const { findProp } = require("./components");

/** The component tags (opening ones) whose attributes are being typed: refs from the scanner plus the stack for slots. */
function openTags(refs) {
  return refs.filter((r) => !r.closing);
}

/** The component that contains a `<Slot>`: the nearest enclosing opened (not self-closed) component. */
function parentOf(refs, slot) {
  const stack = [];
  for (const r of refs) {
    if (r === slot) return stack[stack.length - 1] || null;
    if (r.name === "Slot") continue;
    if (r.closing) {
      for (let i = stack.length - 1; i >= 0; i--) if (stack[i].name === r.name) { stack.length = i; break; }
    } else if (!r.selfClosing && r.tagEnd !== null) {
      stack.push(r);
    }
  }
  return null;
}

/**
 * What is being typed at `offset`:
 *  {type: 'tag', prefix, start, end}                                  after `<` and the first letters of a component name
 *  {type: 'attribute', ref, prefix, start, end, used}                 a prop name inside an open component tag
 *  {type: 'value', ref, attr, prefix, start, end, quoted}             the value of a prop (inside quotes, or right after `=`)
 *  {type: 'slot', ref, parent, prefix, start, end}                    the name of a `<Slot name="...">`
 */
function contextAt(src, offset) {
  // 1) typing a tag name
  const before = src.slice(Math.max(0, offset - 80), offset);
  const tag = /<([A-Z][A-Za-z0-9_.]*)?$/.exec(before);
  if (tag && !/^\s*\?/.test(src.slice(offset - before.length + tag.index + 1, offset - before.length + tag.index + 2))) {
    const prefix = tag[1] || "";
    const afterName = /^[A-Za-z0-9_.]*/.exec(src.slice(offset))[0];
    const lt = offset - prefix.length - 1;
    // not inside <?php ... ?> code or an HTML comment
    const lastOpen = src.lastIndexOf("<?", lt);
    const lastClose = src.lastIndexOf("?>", lt);
    const comment = src.lastIndexOf("<!--", lt) > src.lastIndexOf("-->", lt);
    if (!(lastOpen > lastClose) && !comment) return { type: "tag", prefix, start: offset - prefix.length, end: offset + afterName.length };
  }

  // 2) inside an open component tag
  const refs = scanComponents(src);
  const ref = openTags(refs).find((r) => offset > r.end && offset > r.tagStart && (r.tagEnd === null ? lineEndsOrNoMoreTags(src, r, offset) : offset < r.tagEnd));
  if (!ref) return null;

  for (const attr of ref.attrs) {
    if (attr.valueType === "string" && attr.valueStart <= offset && (attr.valueEnd === null || offset <= attr.valueEnd)) {
      const prefix = src.slice(attr.valueStart, offset);
      if (ref.name === "Slot" && camel(attr.name) === "name") {
        const parent = parentOf(refs, ref);
        return { type: "slot", ref, parent, attr, prefix, start: attr.valueStart, end: attr.valueEnd ?? offset };
      }
      return { type: "value", ref, attr, prefix, start: attr.valueStart, end: attr.valueEnd ?? offset, quoted: true };
    }
    if (attr.valueType === null && attr.equals !== null && offset > attr.equals) {
      return { type: "value", ref, attr, prefix: "", start: offset, end: offset, quoted: false };
    }
    if (attr.valueType === null && attr.nameStart <= offset && offset <= attr.nameEnd) {
      return { type: "attribute", ref, prefix: src.slice(attr.nameStart, offset), start: attr.nameStart, end: attr.nameEnd, used: usedNames(ref, attr) };
    }
    if (attr.valueType !== null && attr.valueType !== "spread" && attr.valueStart !== null && offset >= attr.nameStart && offset <= attr.nameEnd) {
      return { type: "attribute", ref, prefix: src.slice(attr.nameStart, offset), start: attr.nameStart, end: attr.nameEnd, used: usedNames(ref, attr) };
    }
    // inside { } of a brace value or spread: no suggestions
    if ((attr.valueType === "brace" || attr.valueType === "spread") && attr.valueStart <= offset && (attr.valueEnd === null || offset <= attr.valueEnd)) return null;
  }
  return { type: "attribute", ref, prefix: "", start: offset, end: offset, used: usedNames(ref, null) };
}

/** A tag with no `>` yet: only treat the cursor as inside it when no other tag starts between the name and the cursor. */
function lineEndsOrNoMoreTags(src, ref, offset) {
  return !/[<>]/.test(src.slice(ref.end, offset));
}

function usedNames(ref, except) {
  const used = new Set();
  for (const a of ref.attrs) if (a.name && a !== except) used.add(camel(a.name));
  return used;
}

/**
 * Problems in the opening tags of documented components.
 * @param {string} src
 * @param {(tag: string) => ({file: string, spec: object}|null)} lookup
 * @returns {{start: number, end: number, message: string, kind: string, tag: string, prop?: string, values?: string[], suggestion?: string, missing?: string[], insertAt?: number}[]}
 */
function diagnose(src, lookup) {
  const problems = [];
  for (const ref of scanComponents(src)) {
    if (ref.closing || ref.name === "Slot") continue;
    const found = lookup(ref.name);
    if (!found) continue;
    const { spec } = found;

    if (spec.deprecated) {
      problems.push({ start: ref.start, end: ref.end, kind: "deprecated", tag: ref.name, message: `<${ref.name}> is deprecated${spec.deprecated === true ? "" : ": " + spec.deprecated}` });
    }
    const documented = spec.props.filter((p) => !p.inferred);
    if (!documented.length) continue; // an undocumented component is never flagged

    const slotNames = new Set(spec.slots.map((s) => camel(s.name)));
    const hasSpread = ref.attrs.some((a) => a.valueType === "spread");

    for (const attr of ref.attrs) {
      if (!attr.name) continue;
      const prop = findProp(spec, attr.name);
      if (!prop) {
        if (slotNames.has(camel(attr.name))) continue;
        const suggestion = nearest(camel(attr.name), spec.props.map((p) => p.name));
        problems.push({
          start: attr.nameStart, end: attr.nameEnd, kind: "unknown", tag: ref.name, prop: attr.name, suggestion,
          message: `<${ref.name}> has no prop "${attr.name}"` + (suggestion ? `. Did you mean "${suggestion}"?` : ""),
        });
        continue;
      }
      if (prop.deprecated) {
        problems.push({ start: attr.nameStart, end: attr.nameEnd, kind: "deprecated-prop", tag: ref.name, prop: prop.name, message: `"${prop.name}" is deprecated${prop.deprecated === true ? "" : ": " + prop.deprecated}` });
      }
      if (attr.valueType === "string" && attr.valueEnd !== null && prop.values.length && prop.kind === "string") {
        const value = src.slice(attr.valueStart, attr.valueEnd);
        if (!value.includes("<?") && !prop.values.includes(value)) {
          problems.push({
            start: attr.valueStart, end: attr.valueEnd, kind: "value", tag: ref.name, prop: prop.name, values: prop.values,
            message: `"${value}" is not one of ${prop.values.map((v) => `"${v}"`).join(", ")} for "${prop.name}"`,
          });
        }
      }
    }

    if (!hasSpread && ref.tagEnd !== null) {
      const have = new Set(ref.attrs.filter((a) => a.name).map((a) => camel(a.name)));
      for (const s of slotNames) have.add(s);
      const missing = documented.filter((p) => p.required && !have.has(camel(p.name)) && !isProvidedBySlot(src, ref, p.name)).map((p) => p.name);
      if (missing.length) {
        problems.push({
          start: ref.start, end: ref.end, kind: "missing", tag: ref.name, missing, insertAt: ref.end,
          message: `<${ref.name}> needs ${missing.map((m) => `"${m}"`).join(", ")}`,
        });
      }
    }
  }
  return problems;
}

/** A prop can also be given by a `<Slot name="x">` inside the tag. */
function isProvidedBySlot(src, ref, propName) {
  if (ref.selfClosing || ref.tagEnd === null) return false;
  const re = new RegExp(`<Slot\\s+name\\s*=\\s*["']${propName}["']`);
  const close = src.indexOf(`</${ref.name}>`, ref.tagEnd);
  return re.test(src.slice(ref.tagEnd, close < 0 ? undefined : close));
}

/** The closest of `candidates` to `word` (edit distance <= 2, or the same name in another case/kebab spelling). */
function nearest(word, candidates) {
  let best = null;
  let bestDistance = 3;
  for (const c of candidates) {
    if (camel(c).toLowerCase() === word.toLowerCase()) return c;
    const d = distance(word.toLowerCase(), c.toLowerCase());
    if (d < bestDistance) {
      best = c;
      bestDistance = d;
    }
  }
  return best;
}

function distance(a, b) {
  const row = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    let prev = row[0];
    row[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = row[j];
      row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return row[b.length];
}

module.exports = { contextAt, diagnose, nearest, parentOf };
