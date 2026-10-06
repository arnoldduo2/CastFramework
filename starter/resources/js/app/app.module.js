"use strict";
/**
 * Tiny request helper for the starter: JSON in, JSON out, with the global CSRF token on every request.
 * Works for PUT and DELETE too (the server reads the JSON body for every verb).
 */
const app = {
  token: document.querySelector('meta[name="csrf-token"]')?.content ?? "",
  base: (document.querySelector('meta[name="base-url"]')?.content ?? "").replace(/\/$/, ""),
  async request(url, { method = "POST", data = null } = {}) {
    const response = await fetch(app.base + url, {
      method,
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN": app.token,
      },
      body: data === null ? undefined : JSON.stringify(data),
    });
    return response.json();
  },
};
