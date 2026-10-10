/*
 * The Cast dock: a small floating button (bottom right) with links to the demo and the documentation while you build your app.
 * The framework adds it to HTML pages in development, so it is still there if you delete your layout and views.
 * It lives in a shadow root on <html>, so your CSS cannot break it and a page swap (the SPA) does not remove it.
 *
 * Turn it off:  CAST_DOCK=false in .env  (or  'enabled' => false  in config/dock.php).  It never appears in production.
 * Hide it for now: the "Hide" button in its menu (remembered in this browser; ?cast-dock=1 brings it back).
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script || document.documentElement.querySelector('[data-cast-dock]')) return;
  var KEY = 'cast.dock.hidden';
  var store = {
    get: function () { try { return localStorage.getItem(KEY); } catch (e) { return null; } },
    set: function (v) { try { v ? localStorage.setItem(KEY, '1') : localStorage.removeItem(KEY); } catch (e) { /* private mode */ } }
  };
  if (/[?&]cast-dock=1\b/.test(location.search)) store.set(false);
  if (store.get()) return;

  var links = [
    ['Demo the Cast Framework', script.getAttribute('data-demo')],
    ['Documentation', script.getAttribute('data-docs')],
    ["What's new", script.getAttribute('data-docs') ? script.getAttribute('data-docs') + '#/whats-new' : null]
  ].filter(function (l) { return l[1]; });

  var host = document.createElement('div');
  host.setAttribute('data-cast-dock', '');
  host.setAttribute('data-cast', 'off');
  var root = host.attachShadow({ mode: 'open' });
  root.innerHTML =
    '<style>' +
    ':host{all:initial}' +
    '.d{position:fixed;right:18px;bottom:48px;z-index:2147483000;font:13px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;color:#e8eaf0}' +
    '.b{width:44px;height:44px;border-radius:50%;border:1px solid #2c3140;background:#12151d;box-shadow:0 6px 20px rgba(0,0,0,.35);cursor:pointer;display:grid;place-items:center;padding:0}' +
    '.b:hover{border-color:#6c8cff}' +
    '.b svg{width:22px;height:22px}' +
    '.m{position:absolute;right:0;bottom:54px;min-width:210px;background:#12151d;border:1px solid #2c3140;border-radius:10px;padding:6px;box-shadow:0 12px 32px rgba(0,0,0,.4);display:none}' +
    '.d.o .m{display:block}' +
    '.m a,.m button{display:block;width:100%;box-sizing:border-box;text-align:left;color:#e8eaf0;background:none;border:0;border-radius:6px;padding:8px 10px;font:inherit;text-decoration:none;cursor:pointer}' +
    '.m a:hover,.m button:hover{background:#1c2130}' +
    '.m small{display:block;color:#8a91a5;padding:6px 10px 2px;font-size:11px}' +
    '.m hr{border:0;border-top:1px solid #2c3140;margin:4px 0}' +
    '.b{position:relative}.n{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;padding:0 5px;border-radius:99px;background:#e5173f;color:#fff;font:700 11px/19px system-ui,sans-serif;text-align:center;box-sizing:border-box}' +
    '.e{border-top:1px solid #2c3140;margin-top:4px;padding-top:4px;max-height:260px;overflow:auto}.e h4{margin:2px 10px 4px;font:600 11px system-ui,sans-serif;color:#ff8da1;text-transform:uppercase;letter-spacing:.05em}' +
    '.e button.i{display:block;white-space:normal}.e .k{display:block;color:#ff8da1;font-size:11px}.e .w{display:block;color:#8a91a5;font-size:11px}' +
    '</style>' +
    '<div class="d"><div class="m" role="menu"></div>' +
    '<button class="b" type="button" aria-label="Cast dock" aria-expanded="false">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="#6c8cff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17l6-6-6-6"/><path d="M12 19h8"/></svg></button></div>';

  var wrap = root.querySelector('.d'), menu = root.querySelector('.m'), btn = root.querySelector('.b');
  var badge = document.createElement('span');
  badge.className = 'n';
  badge.hidden = true;
  btn.appendChild(badge);
  var errorsBox = document.createElement('div');
  links.forEach(function (l) {
    var a = document.createElement('a');
    a.href = l[1]; a.textContent = l[0]; a.setAttribute('role', 'menuitem');
    menu.appendChild(a);
  });
  menu.insertAdjacentHTML('beforeend',
    '<hr><button type="button" data-hide>Hide this</button>' +
    '<small>Off for good: CAST_DOCK=false in .env</small>');

  // Errors the development overlay showed (error-overlay.js remembers them for this tab): a red count on the button and a list to reopen them
  function overlayUrl() {
    return script.src ? script.src.replace(/[^/]*(\?.*)?$/, 'error-overlay.js') : '/cast/error-overlay.js';
  }
  function withOverlay(then) {
    if (window.CastErrorOverlay) return then(window.CastErrorOverlay);
    var s = document.createElement('script');
    s.src = overlayUrl();
    s.onload = function () { if (window.CastErrorOverlay) then(window.CastErrorOverlay); };
    document.head.appendChild(s);
  }
  function errorList() {
    try {
      var list = JSON.parse(sessionStorage.getItem('cast.dev.errors') || '[]');
      return Array.isArray(list) ? list : [];
    } catch (e) { return []; }
  }
  function renderErrors() {
    var list = errorList();
    badge.hidden = !list.length;
    badge.textContent = String(list.length);
    errorsBox.replaceChildren();
    errorsBox.className = 'e';
    errorsBox.hidden = !list.length;
    if (!list.length) return;
    var h = document.createElement('h4');
    h.textContent = 'Errors (' + list.length + ')';
    errorsBox.appendChild(h);
    list.slice(0, 8).forEach(function (e) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'i';
      var kind = document.createElement('span');
      kind.className = 'k';
      kind.textContent = (e.kind || 'Error') + (e.count > 1 ? '  ×' + e.count : '');
      var msg = document.createElement('span');
      msg.textContent = String(e.message || '').slice(0, 90);
      var where = document.createElement('span');
      where.className = 'w';
      where.textContent = (e.file || '') + ':' + (e.line || '');
      b.append(kind, msg, where);
      b.addEventListener('click', function (ev) {
        ev.stopPropagation();
        toggle(false);
        withOverlay(function (o) { o.show(e.debug, { record: false }); });
      });
      errorsBox.appendChild(b);
    });
    var clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = 'Clear errors';
    clear.addEventListener('click', function (ev) {
      ev.stopPropagation();
      try { sessionStorage.removeItem('cast.dev.errors'); } catch (e) { /* blocked */ }
      renderErrors();
      if (window.CastErrorOverlay) window.CastErrorOverlay.clear();
    });
    errorsBox.appendChild(clear);
  }
  menu.insertBefore(errorsBox, menu.firstChild);
  renderErrors();
  window.addEventListener('cast:dev-errors', renderErrors);

  function toggle(open) {
    wrap.classList.toggle('o', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(!wrap.classList.contains('o')); });
  menu.querySelector('[data-hide]').addEventListener('click', function () { store.set(true); host.remove(); });
  document.addEventListener('click', function () { toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });

  document.documentElement.appendChild(host);      // on <html>, not <body>: a body swap or a deleted layout does not take it away
})();
