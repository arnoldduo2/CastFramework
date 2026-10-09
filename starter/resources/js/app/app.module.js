"use strict";
// App-level script: runs once per full page load. Per-page setup belongs in Cast.page() in the page's own module.
document.addEventListener("cast:mounted", (event) => {
  document.documentElement.dataset.lastMounted = event.detail.page;
});

// A Cast form was saved (the add form, or the form inside a modal): close the modal and show the new data.
document.addEventListener("cast:saved", (event) => {
  if (event.detail.data && event.detail.data.type === "redirect") return; // the redirect already loaded the page
  const dialog = event.target.closest && event.target.closest("dialog");
  if (dialog) dialog.close();
  Cast.load(location.pathname + location.search, { push: false });
});

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
