"use strict";
// Loaded on every page (resources/js/app/app.module.js). A page's own script (js/<page>/<page>.module.js) loads after it.
// With the built-in SPA client a page's content is swapped without a reload, so use event delegation (listen on `document`,
// as below) for anything inside the content: it keeps working after a swap. Per-page setup belongs in Cast.page({mount, destroy}).

// Buttons with data-copy="text" copy that text (the terminal boxes on the welcome page use it).
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-copy]");
  if (!button || !navigator.clipboard) return;
  navigator.clipboard.writeText(button.getAttribute("data-copy")).then(() => {
    const label = button.textContent;
    button.textContent = "Copied";
    setTimeout(() => (button.textContent = label), 1400);
  });
});
