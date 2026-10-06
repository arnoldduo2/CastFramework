<style>
  :root { --c-bg: #e8e8e8; --c-card: #f8f8f8; --c-text: #212121; --c-muted: #6d6d6d; --c-border: #cecece; --c-accent: var(--primary-color, #00a000); }
  @media (prefers-color-scheme: dark) { :root { --c-bg: #15171a; --c-card: #1f2226; --c-text: #e6e6e6; --c-muted: #9aa0a6; --c-border: #343a40; } }
  html[data-color-scheme="dark"] { --c-bg: #15171a; --c-card: #1f2226; --c-text: #e6e6e6; --c-muted: #9aa0a6; --c-border: #343a40; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
         background: var(--c-bg); color: var(--c-text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .card { width: 100%; max-width: 460px; background: var(--c-card); border: 1px solid var(--c-border); border-radius: 10px; padding: 32px 28px; text-align: center; box-shadow: 0 6px 24px rgba(0, 0, 0, .08); }
  .code { font-size: 64px; line-height: 1; font-weight: 700; color: var(--c-accent); margin: 0 0 8px; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  p { margin: 0 0 20px; color: var(--c-muted); }
  .hint { font-size: 13px; }
  a.btn { display: inline-block; padding: 8px 18px; border-radius: 5px; background: var(--c-accent); color: #fff; text-decoration: none; font-weight: 600; }
  .meta { margin-top: 18px; font-size: 12px; color: var(--c-muted); }
</style>
