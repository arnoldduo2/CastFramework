/*
 * The development error overlay: shows a server error with the code where it happened (the failing part underlined in red), the stack,
 * "Open in editor" links and a link to the full error page, on top of whatever page is open. It is what the Cast client shows when the
 * server answers with `data.debug` (development only: that object does not exist in production).
 *
 * Use it from a front end of your own (React, Vue, Next.js ...) that calls the CastFramework API:
 *
 *   <script src="http://localhost:8000/cast/error-overlay.js"></script>          (in development only)
 *   const body = await response.json();
 *   if (body.data && body.data.debug) CastErrorOverlay.show(body.data.debug);
 *
 * It lives in a shadow root, so your CSS cannot break it, and everything is inserted as text. Esc or a click outside closes it.
 */
(function () {
  "use strict";
  if (window.CastErrorOverlay) return;

  var HOST = null;
  // links: only addresses that open an editor or a page; never javascript:
  function safe(url) {
    return typeof url === "string" && /^(https?:\/\/|[a-z][a-z0-9+.-]*:\/\/|\/)/i.test(url) && !/^javascript:/i.test(url) ? url : null;
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function link(label, href, cls) {
    var url = safe(href);
    if (!url) return null;
    var a = el("a", cls || "btn", label);
    a.href = url;
    if (/^https?:/i.test(url)) {
      a.target = "_blank";
      a.rel = "noopener";
    }
    return a;
  }

  var CSS =
    ":host{all:initial}" +
    ".back{position:fixed;inset:0;z-index:2147483600;background:rgba(10,12,16,.62);display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:4vh 14px;font:14px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif;color:#1a1c1f}" +
    ".panel{background:#fff;border-radius:12px;max-width:980px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.45);overflow:hidden}" +
    ".head{padding:18px 22px;border-left:5px solid #e5173f}" +
    ".row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}" +
    ".badge{font-size:12px;font-weight:700;letter-spacing:.04em;padding:2px 9px;border-radius:99px;background:rgba(229,23,63,.1);color:#e5173f}" +
    ".kind{font:12px ui-monospace,Menlo,Consolas,monospace;color:#6b7078}" +
    "h2{margin:0 0 10px;font-size:19px;line-height:1.35;font-weight:600;word-break:break-word;white-space:pre-wrap}" +
    ".where{font:13px ui-monospace,Menlo,Consolas,monospace;color:#6b7078;display:flex;gap:8px;align-items:center;flex-wrap:wrap}" +
    ".btn{font:600 12px system-ui,sans-serif;text-decoration:none;padding:3px 10px;border:1px solid #d9dce1;border-radius:6px;color:#2858d6;background:#fff;cursor:pointer}" +
    ".btn:hover{border-color:#2858d6}" +
    ".x{margin-left:auto;font-size:20px;line-height:1;padding:2px 10px}" +
    ".cf{background:#fbfbfc;border-top:1px solid #e3e5e8;border-bottom:1px solid #e3e5e8;overflow-x:auto;padding:6px 0;font:13px/1.65 ui-monospace,'Cascadia Code',Menlo,Consolas,monospace}" +
    ".cl{display:flex;min-width:max-content;border-left:4px solid transparent}" +
    ".cn{flex:none;width:52px;padding-right:14px;text-align:right;color:#8b919a;user-select:none}" +
    ".cl code{font:inherit;white-space:pre;color:#1a1c1f;padding-right:20px}" +
    ".err{background:rgba(229,23,63,.09);border-left-color:#e5173f}.err .cn{color:#e5173f;font-weight:700}" +
    ".u{text-decoration:underline wavy #e5173f;text-decoration-thickness:2px;text-underline-offset:4px;background:rgba(229,23,63,.16);border-radius:2px}" +
    ".trace{max-height:34vh;overflow:auto}" +
    ".t{display:flex;gap:12px;align-items:baseline;padding:7px 22px;border-bottom:1px solid #eef0f2;font:12.5px ui-monospace,Menlo,Consolas,monospace}" +
    ".t.v{color:#8b919a}.t .i{color:#8b919a;min-width:26px}.t .c{margin-left:auto;color:#6b7078;text-align:right;word-break:break-all}" +
    ".foot{padding:10px 22px;color:#6b7078;font-size:12px}" +
    "@media (prefers-color-scheme:dark){.panel{background:#1b1d20;color:#e8eaed}.cf{background:#16181b;border-color:#2c2f34}.cl code{color:#e8eaed}.btn{background:#1b1d20;border-color:#2c2f34;color:#7fa4ff}.t{border-color:#2c2f34}.kind,.where,.foot{color:#9aa0a8}h2{color:#e8eaed}}";

  function close() {
    if (HOST) {
      HOST.remove();
      HOST = null;
    }
    document.removeEventListener("keydown", onKey, true);
  }
  function onKey(e) {
    if (e.key === "Escape") close();
  }

  function show(debug) {
    if (!debug || typeof debug !== "object") return;
    close();
    HOST = document.createElement("div");
    HOST.setAttribute("data-cast-error-overlay", "");
    HOST.setAttribute("data-cast", "off");
    var root = HOST.attachShadow({ mode: "open" });
    var style = el("style");
    style.textContent = CSS;
    var back = el("div", "back");
    var panel = el("div", "panel");
    panel.setAttribute("role", "alertdialog");
    panel.setAttribute("aria-label", "Server error");

    var head = el("div", "head");
    var row = el("div", "row");
    row.append(el("span", "badge", String(debug.severity || "ERROR")), el("span", "kind", String(debug.kind || "")));
    var closeBtn = el("button", "btn x", "×");
    closeBtn.type = "button";
    closeBtn.setAttribute("aria-label", "Close");
    closeBtn.addEventListener("click", close);
    row.append(closeBtn);
    head.append(row, el("h2", "", String(debug.message || "Server error")));
    var where = el("div", "where");
    where.append(el("span", "", String(debug.file || "") + ":" + String(debug.line || "")));
    var open = link("Open in editor ↗", debug.editor);
    var full = link("Full error page ↗", debug.url);
    if (open) where.append(open);
    if (full) where.append(full);
    head.append(where);
    panel.append(head);

    if (Array.isArray(debug.code) && debug.code.length) {
      var cf = el("div", "cf");
      debug.code.forEach(function (l) {
        var line = el("div", "cl" + (l.error ? " err" : ""));
        var code = el("code");
        var text = String(l.text == null ? "" : l.text);
        var f = l.error && Array.isArray(debug.focus) ? debug.focus : null;
        if (f && f[0] >= 0 && f[1] > 0 && f[0] < text.length) {
          code.append(document.createTextNode(text.slice(0, f[0])), el("span", "u", text.slice(f[0], f[0] + f[1])), document.createTextNode(text.slice(f[0] + f[1])));
        } else {
          code.textContent = text || " ";
        }
        line.append(el("span", "cn", String(l.n)), code);
        cf.append(line);
      });
      panel.append(cf);
    }

    if (Array.isArray(debug.trace) && debug.trace.length) {
      var trace = el("div", "trace");
      debug.trace.forEach(function (f) {
        var t = el("div", "t" + (f.app === false ? " v" : ""));
        t.append(el("span", "i", "#" + f.index));
        var loc = link(String(f.file) + ":" + String(f.line), f.editor, "loc");
        if (loc) {
          loc.style.color = "inherit";
          loc.style.textDecoration = "none";
          loc.title = "Open in editor";
        } else {
          loc = el("span", "", String(f.file) + ":" + String(f.line));
        }
        t.append(loc, el("span", "c", String(f.context || "")));
        trace.append(t);
      });
      panel.append(trace);
    }
    panel.append(el("div", "foot", "Development only: this overlay is not sent in production. Esc closes it."));

    back.addEventListener("click", function (e) {
      if (e.target === back) close();
    });
    back.append(panel);
    root.append(style, back);
    document.documentElement.appendChild(HOST);
    document.addEventListener("keydown", onKey, true);
    closeBtn.focus();
  }

  window.CastErrorOverlay = { show: show, close: close };
})();
