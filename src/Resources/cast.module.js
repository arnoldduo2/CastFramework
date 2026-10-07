/*!
 * Cast client: the SPA layer for CastFramework. Plain JavaScript, no dependencies (jQuery is optional).
 *
 * Add it once in your layout's <head> with <?= __cast($authguard) ?>. A page opts in on the server with 'spa' => true.
 *
 *   Cast.load(url, {type, target})   load a page / partial / modal (the server answers with a JSON envelope)
 *   Cast.http({url, data, type})     JSON request with the CSRF token; mirrors an axios-style helper
 *   Cast.page({mount, destroy})      the lifecycle of a page's script (it runs on every visit to the page)
 *   Cast.configure({http, modal, onError})
 *
 * Events (bubbling, on the container that changed): `cast:mounted`, `cast:destroy`, `cast:error`, `cast:invalid`, `cast:navigate`.
 */
(function () {
  "use strict";
  if (window.Cast) return;

  const script = document.currentScript;
  const settings = Object.assign({ castRoot: "body", castView: "#cast-view", castGuard: "", castBase: "" }, script ? script.dataset : {});
  const base = String(settings.castBase || "").replace(/\/+$/, ""); // the folder the app lives in (APP_BASE_PATH), "" at the web root

  /** A root-relative app path ("/items") with the app's base folder in front. Other URLs are returned as they are. */
  const url = (path) => (typeof path === "string" && path.startsWith("/") && !path.startsWith("//") && base && path !== base && !path.startsWith(base + "/") ? base + path : path);

  const config = {
    root: settings.castRoot,
    view: settings.castView,
    http: null, // replace Cast.http (for example with an existing app.axios wrapper)
    modal: null, // function (envelope) that opens a modal your own way
    onError: null, // function (envelope) called for error envelopes
  };

  const state = {
    guard: settings.castGuard,
    key: null,
    ctx: null, // the mounted page: {key, def, el, listeners, controller}
    nav: null, // AbortController of the navigation in flight
    pending: null, // the key of the page being swapped in (its script registers itself under this key)
    scripts: new Set(), // script URLs that have already run
    started: false,
    depth: 0,
  };

  const pages = new Map(); // page key => {mount, destroy}

  // ------------------------------------------------------------------ helpers

  const root = document.documentElement;
  const $ = (selector, scope = document) => {
    try {
      return scope.querySelector(selector);
    } catch (e) {
      return null; // an invalid selector is the same as no container
    }
  };
  const abs = (url) => new URL(url, location.href);
  const sameOrigin = (url) => {
    try {
      return abs(url).origin === location.origin;
    } catch (e) {
      return false;
    }
  };
  // The identity of an asset: its address without the #hash and without the ?v= cache-busting version (a development
  // server gives every request a new one, and the same file must not be loaded twice because of it).
  const normalise = (url) => {
    const u = abs(url);
    u.hash = "";
    u.searchParams.delete("v");
    return u.href;
  };

  function emit(el, name, detail) {
    (el || document).dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
  }

  function busy(on) {
    state.depth = Math.max(0, state.depth + (on ? 1 : -1));
    root.classList.toggle("cast-busy", state.depth > 0);
  }

  // --------------------------------------------------------------------- CSRF

  function token() {
    return ($('meta[name="csrf-token"]') || {}).content || "";
  }

  function setToken(value) {
    if (!value) return;
    const meta = $('meta[name="csrf-token"]');
    if (meta) meta.content = value;
    document.querySelectorAll('input[name="_token"], #_global_csrf_token').forEach((input) => (input.value = value));
  }

  // --------------------------------------------------------------------- http

  /**
   * A request with the CSRF token, JSON in and out. Always resolves with the parsed `{status, msg, data}` body (an
   * error body for failures), so callers check `res.status`. Redirect and reload envelopes are followed.
   *   Cast.http({url, data, type: "POST"|"PUT"|"PATCH"|"DELETE"|"GET", isform: false, busy: true, follow: true})
   */
  async function http(options) {
    if (config.http) return config.http(options);

    const method = String(options.method || options.type || "POST").toUpperCase();
    const headers = Object.assign(
      {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": token(),
        // so that a server redirect becomes a JSON envelope instead of the redirect target page being fetched
        "X-Cast-Request": "1",
        "X-Cast-Guard": state.guard || "",
      },
      options.headers || {}
    );

    let target = url(options.url);
    let body;
    if (options.data != null) {
      if (method === "GET" || method === "HEAD") {
        const u = abs(target);
        Object.entries(options.data).forEach(([k, v]) => u.searchParams.set(k, v));
        target = u.pathname + u.search;
      } else if (options.isform || options.data instanceof FormData) {
        body = options.data instanceof FormData ? options.data : new FormData(options.data);
        if (method !== "POST") {
          body.append("_method", method); // a multipart body is only parsed by PHP for POST
        }
      } else {
        headers["Content-Type"] = "application/json";
        body = JSON.stringify(options.data);
      }
    }

    if (options.busy !== false) busy(true);
    let res;
    try {
      const response = await fetch(target, {
        method: body instanceof FormData && method !== "POST" ? "POST" : method,
        credentials: "same-origin",
        headers,
        body,
        signal: options.signal,
      });
      res = await parse(response);
    } catch (e) {
      res = e && e.name === "AbortError" ? { status: "aborted", msg: "", data: {} } : { status: "error", msg: "The server could not be reached.", data: { type: "error", code: 0 } };
    } finally {
      if (options.busy !== false) busy(false);
    }

    if (res.data && res.data.csrf) setToken(res.data.csrf);
    if (options.follow !== false) await follow(res);
    return res;
  }

  async function parse(response) {
    const text = await response.text();
    try {
      const json = JSON.parse(text);
      if (json && typeof json === "object") {
        json.http = response.status;
        return json;
      }
    } catch (e) {
      /* not JSON: handled below */
    }
    return { status: "error", msg: response.statusText || "Unexpected response from the server.", data: { type: "error", code: response.status }, http: response.status, raw: true };
  }

  async function follow(res) {
    const data = res && res.data;
    if (!data) return;
    if (data.type === "redirect" && data.url) {
      if (sameOrigin(data.url)) await load(data.url);
      else location.assign(data.url);
    } else if (data.type === "reload") {
      location.assign(data.url || location.href);
    }
  }

  // ------------------------------------------------------------------- assets

  const hasStyle = (url) => Array.from(document.querySelectorAll("link[rel=stylesheet]")).some((l) => normalise(l.href) === normalise(url));

  function loadStyle(url, pageLevel) {
    if (hasStyle(url)) return Promise.resolve();
    return new Promise((resolve) => {
      const link = document.createElement("link");
      link.rel = "stylesheet";
      link.className = "resources";
      link.href = url;
      if (pageLevel) link.setAttribute("data-cast-page", "");
      link.onload = link.onerror = () => resolve();
      document.head.appendChild(link);
      setTimeout(resolve, 4000); // never block a page on a slow stylesheet
    });
  }

  function loadScript(url, pageLevel) {
    const key = normalise(url);
    if (state.scripts.has(key)) return Promise.resolve();
    state.scripts.add(key);
    return new Promise((resolve) => {
      const el = document.createElement("script");
      el.src = url;
      if (pageLevel) el.setAttribute("data-cast-page", "");
      el.onload = el.onerror = () => resolve();
      document.body.appendChild(el);
    });
  }

  /** Load the assets a page needs and drop the previous page's own ones. Same-origin URLs only. */
  async function syncAssets(env) {
    const css = (env.css || []).filter(sameOrigin);
    const js = (env.js || []).filter(sameOrigin);
    const keep = new Set([...css, ...js].map(normalise));
    const own = new Set((env.own || []).map(normalise)); // this page's own files (the rest belong to the layout)
    const pageLevel = (url) => own.has(normalise(url));

    document.querySelectorAll("link[data-cast-page], script[data-cast-page]").forEach((el) => {
      const url = el.href || el.src;
      if (url && !keep.has(normalise(url))) el.remove();
    });

    await Promise.all(css.map((url) => loadStyle(url, pageLevel(url))));
    for (const url of js) await loadScript(url, pageLevel(url)); // in order: page modules may depend on each other
  }

  // ------------------------------------------------------------ page lifecycle

  /**
   * Register a page's script. Call it from the page module (it runs on every visit to the page):
   *   Cast.page({ mount(ctx) { ctx.on("click", ".js-save", save); }, destroy(ctx) {} })
   * The key is the page being loaded (`parentName.pageName`); pass it first to register another one: Cast.page("items.items", {...}).
   * `ctx.el` is the container, `ctx.on(type, selector, handler)` adds a delegated listener that is removed on leave,
   * and `ctx.signal` aborts when the page is left (pass it to fetch).
   */
  function page(first, second) {
    const key = typeof first === "string" ? first : currentKey();
    const def = typeof first === "string" ? second : first;
    if (!key || !def) return;
    pages.set(key, def);
    // the page script of the first visit can finish after the container was filled: mount it now
    if (state.started && state.ctx && state.ctx.key === key && !state.ctx.def) mountContext(state.ctx);
  }

  function currentKey() {
    if (state.pending) return state.pending;
    if (state.key) return state.key;
    const el = $("[data-cast-page]:not(script):not(link)");
    return el ? el.getAttribute("data-cast-page") : "";
  }

  function makeContext(key, el) {
    const controller = new AbortController();
    const listeners = [];
    return {
      key,
      el,
      def: null,
      signal: controller.signal,
      controller,
      listeners,
      on(type, selector, handler) {
        const fn = typeof selector === "function" ? selector : (event) => {
          const hit = event.target.closest && event.target.closest(selector);
          if (hit && el.contains(hit)) handler.call(hit, event, hit);
        };
        el.addEventListener(type, fn);
        listeners.push([el, type, fn]);
      },
    };
  }

  function mountContext(ctx) {
    const def = pages.get(ctx.key);
    if (!def) return;
    ctx.def = def;
    if (typeof def.mount === "function") def.mount(ctx);
  }

  function destroyContext() {
    const ctx = state.ctx;
    if (!ctx) return;
    state.ctx = null;
    emit(ctx.el, "cast:destroy", { page: ctx.key });
    try {
      if (ctx.def && typeof ctx.def.destroy === "function") ctx.def.destroy(ctx);
    } finally {
      ctx.listeners.forEach(([target, type, fn]) => target.removeEventListener(type, fn));
      ctx.controller.abort();
    }
  }

  function mount(key, el, type) {
    destroyContext();
    state.key = key || null;
    if (el && key) el.setAttribute("data-cast-page", key);
    state.ctx = el ? makeContext(key, el) : null;
    if (state.ctx) mountContext(state.ctx);
    if (el) emit(el, "cast:mounted", { page: key, type: type || "partial" });
  }

  // --------------------------------------------------------------------- swap

  function showError(target, env, retryUrl) {
    const data = env.data || {};
    const box = document.createElement("div");
    box.className = "cast-error";
    box.setAttribute("role", "alert");
    const title = document.createElement("h2");
    title.textContent = data.code ? String(data.code) : "Error";
    const text = document.createElement("p");
    text.textContent = env.msg || "Something went wrong.";
    box.append(title, text);
    if (retryUrl) {
      const retry = document.createElement("button");
      retry.type = "button";
      retry.className = "cast-retry";
      retry.textContent = "Try again";
      retry.addEventListener("click", () => load(retryUrl, { push: false }));
      box.append(retry);
    }
    if (target) target.replaceChildren(box);
    emit(target || document, "cast:error", env);
    if (typeof config.onError === "function") config.onError(env);
  }

  /** After a navigation: tell screen readers the page changed, and move focus to the new content (keyboard users start there). */
  function announce(container, title) {
    let live = document.getElementById("cast-announcer");
    if (!live) {
      live = document.createElement("div");
      live.id = "cast-announcer";
      live.className = "cast-sr-only";
      live.setAttribute("role", "status");
      live.setAttribute("aria-live", "polite");
      document.body.appendChild(live);
    }
    live.textContent = "";
    setTimeout(() => (live.textContent = title || document.title), 50);

    // the container itself has no box (display: contents), so focus the first heading, or the first element, inside it
    const start = container && container.isConnected ? container.querySelector("[autofocus], h1, h2, h3") || container.firstElementChild : null;
    if (start) {
      if (!start.hasAttribute("tabindex")) start.setAttribute("tabindex", "-1");
      start.setAttribute("data-cast-focus", "");
      start.focus({ preventScroll: true });
    }
  }

  async function swap(env, opts) {
    const data = env.data;
    const isPage = data.type === "page";
    const target = isPage ? $(config.root) : $(data.target || config.view);
    if (!target) return location.assign(opts.url || data.url || location.href);

    state.pending = data.page || null;
    try {
      await syncAssets(data);
    } finally {
      state.pending = null;
    }

    destroyContext();
    target.innerHTML = data.html; // server-rendered markup (inline scripts are not run: page code belongs in the page module)
    target.removeAttribute("data-cast-lazy");
    target.removeAttribute("aria-busy");
    if (data.title != null) document.title = data.title;
    if (data.guard != null) state.guard = data.guard;
    if (data.csrf) setToken(data.csrf);

    if (opts.push !== false && data.url) history.pushState({ cast: 1 }, "", data.url);
    else if (opts.replace && data.url) history.replaceState({ cast: 1 }, "", data.url);

    if (opts.scroll != null) window.scrollTo(0, opts.scroll);
    else if (opts.push !== false && !opts.initial) window.scrollTo(0, 0);

    // for a whole page, the content container is inside the new body
    const container = isPage ? $(config.view) || target : target;
    mount(data.page || (container && container.getAttribute("data-cast-page")) || "", container, data.type);
    if (opts.push !== false || opts.scroll != null) announce(container, data.title); // a navigation, not a refresh
    emit(document, "cast:navigate", { url: data.url, page: data.page, type: data.type });
  }

  // --------------------------------------------------------------------- modal

  function openModal(env) {
    if (typeof config.modal === "function") return config.modal(env);
    const data = env.data;
    const dialog = document.createElement("dialog");
    dialog.className = "cast-modal " + (data.modalClass || "");
    const close = document.createElement("button");
    close.type = "button";
    close.className = "cast-modal-close";
    close.setAttribute("aria-label", "Close");
    close.textContent = "×";
    const body = document.createElement("div");
    body.className = "cast-modal-body";
    body.innerHTML = data.html;
    if (data.form) dialog.setAttribute("data-form", data.form);
    dialog.append(close, body);
    document.body.appendChild(dialog);

    const done = () => {
      emit(dialog, "cast:destroy", { page: "modal" });
      dialog.remove();
    };
    close.addEventListener("click", () => dialog.close());
    dialog.addEventListener("close", done);
    dialog.addEventListener("click", (event) => {
      if (event.target === dialog) dialog.close(); // a click on the backdrop
    });
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
    emit(body, "cast:mounted", { page: "modal", type: "modal" });
    return dialog;
  }

  // ---------------------------------------------------------------------- load

  /**
   * Load a URL through the server and show it.
   *   type: "partial" (default, fills the content container), "page" (replaces the whole body) or "modal"
   *   target: CSS selector of the container for a partial
   *   push: false to skip the history entry; replace: true to replace the current one
   */
  async function load(path, opts = {}) {
    const url = window.Cast.url(path);
    if (!sameOrigin(url)) return void location.assign(url);

    if (state.nav) state.nav.abort();
    const controller = (state.nav = new AbortController());
    const type = opts.type || "partial";
    const target = opts.target || config.view;

    saveScroll();
    busy(true);
    let res;
    try {
      const response = await fetch(url, {
        method: "GET",
        credentials: "same-origin",
        signal: controller.signal,
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-Cast-Request": "1",
          "X-Cast-Type": type,
          "X-Cast-Target": target,
          "X-Cast-Guard": state.guard || "",
        },
      });
      res = await parse(response);
    } catch (e) {
      if (e && e.name === "AbortError") return;
      res = { status: "error", msg: "The server could not be reached.", data: { type: "error", code: 0 } };
    } finally {
      busy(false);
      if (state.nav === controller) state.nav = null;
    }

    if (res.data && res.data.csrf) setToken(res.data.csrf);

    // not a Cast answer (a proxy page, an old server): fall back to a normal page load
    if (res.raw && opts.type !== "modal") return void location.assign(url);

    const data = res.data || {};
    if (res.status === "error") {
      if (data.type === "reload") return void location.assign(data.url || url);
      return showError(type === "page" ? $(config.root) : $(target), res, url);
    }

    switch (data.type) {
      case "redirect":
        return sameOrigin(data.url) ? load(data.url, { replace: opts.replace }) : void location.assign(data.url);
      case "reload":
        return void location.assign(data.url || url);
      case "modal":
        return openModal(res);
      default:
        return swap(res, Object.assign({ url }, opts));
    }
  }

  // ------------------------------------------------------------------- history

  function saveScroll() {
    try {
      history.replaceState(Object.assign({}, history.state, { cast: 1, scroll: window.scrollY }), "");
    } catch (e) {
      /* history may be unavailable in sandboxed frames */
    }
  }

  window.addEventListener("popstate", (event) => {
    if (!event.state || !event.state.cast) return; // an anchor (#hash) change
    load(location.pathname + location.search, { push: false, scroll: event.state.scroll || 0 });
  });

  // --------------------------------------------------------------------- links

  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest && event.target.closest("a[href]");
    if (!link || !state.started) return;

    const mode = link.getAttribute("data-cast");
    if (mode === "off" || link.hasAttribute("download")) return;
    const targetAttr = link.getAttribute("target");
    if (targetAttr && targetAttr !== "_self") return;

    const url = abs(link.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return; // in-page anchor

    // only inside a Cast page; a link marked data-cast always goes through Cast
    if (!state.key && !mode) return;

    event.preventDefault();
    load(url.pathname + url.search + url.hash, {
      type: mode === "modal" || mode === "page" ? mode : "partial",
      target: link.getAttribute("data-cast-target") || undefined,
    });
  });

  // --------------------------------------------------------------------- forms

  function showFieldErrors(form, res) {
    const errors = (res.data && res.data.errors) || {};
    form.querySelectorAll("[data-cast-invalid]").forEach((el) => el.remove());
    Object.entries(errors).forEach(([field, message]) => {
      const holder = form.querySelector('[data-cast-error="' + CSS.escape(field) + '"]');
      const text = Array.isArray(message) ? message[0] : message;
      if (holder) {
        holder.textContent = text;
        return;
      }
      const input = form.querySelector('[name="' + CSS.escape(field) + '"]');
      if (!input) return;
      const note = document.createElement("div");
      note.className = "cast-error-text";
      note.setAttribute("data-cast-invalid", "");
      note.setAttribute("role", "alert");
      note.textContent = text;
      input.after(note);
    });
    emit(form, "cast:invalid", res);
  }

  document.addEventListener("submit", async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-cast-form") || event.defaultPrevented) return;
    event.preventDefault();

    const method = (form.getAttribute("method") || "POST").toUpperCase();
    const res = await (config.http || http)({
      url: form.getAttribute("action") || location.pathname + location.search,
      method: method === "GET" ? "GET" : "POST",
      data: method === "GET" ? Object.fromEntries(new FormData(form)) : new FormData(form),
      isform: method !== "GET",
    });

    const note = form.querySelector("[data-cast-message]");
    if (note) note.textContent = res.status === "error" && res.http !== 422 ? res.msg || "" : "";

    if (res.status === "error") {
      if (res.http === 422) showFieldErrors(form, res);
      else if (!note) showError(null, res);
      else emit(form, "cast:error", res);
    } else if (res.status === "success") {
      emit(form, "cast:saved", res);
    }
  });

  // ---------------------------------------------------------------------- start

  function start() {
    state.started = true;
    document.querySelectorAll("script[src]").forEach((s) => state.scripts.add(normalise(s.src)));
    try {
      history.replaceState(Object.assign({}, history.state, { cast: 1 }), "");
    } catch (e) {
      /* ignore */
    }

    const view = $(config.view);
    if (!view || !view.hasAttribute("data-cast-page")) return; // not a Cast page

    const key = view.getAttribute("data-cast-page");
    state.key = key;
    if (view.hasAttribute("data-cast-lazy")) {
      load(view.getAttribute("data-cast-url") || location.pathname + location.search, { replace: true, push: false, initial: true });
    } else {
      mount(key, view, "partial");
    }
  }

  window.Cast = {
    version: "0.3.0",
    load,
    http: (options) => http(options),
    page,
    token,
    url,
    configure(options) {
      Object.assign(config, options || {});
      return window.Cast;
    },
    get guard() {
      return state.guard;
    },
    get page_key() {
      return state.key;
    },
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
