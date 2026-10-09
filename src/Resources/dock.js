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
    '</style>' +
    '<div class="d"><div class="m" role="menu"></div>' +
    '<button class="b" type="button" aria-label="Cast dock" aria-expanded="false">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="#6c8cff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17l6-6-6-6"/><path d="M12 19h8"/></svg></button></div>';

  var wrap = root.querySelector('.d'), menu = root.querySelector('.m'), btn = root.querySelector('.b');
  links.forEach(function (l) {
    var a = document.createElement('a');
    a.href = l[1]; a.textContent = l[0]; a.setAttribute('role', 'menuitem');
    menu.appendChild(a);
  });
  menu.insertAdjacentHTML('beforeend',
    '<hr><button type="button" data-hide>Hide this</button>' +
    '<small>Off for good: CAST_DOCK=false in .env</small>');
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
