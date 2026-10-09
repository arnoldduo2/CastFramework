/**
 * Browser checks for the SPA client, against the starter app.
 *   cd starter && php -S 127.0.0.1:8099 -t public public/index.php &
 *   NODE_PATH=$(npm root -g) BASE=http://127.0.0.1:8099 node tests/e2e/spa.e2e.js
 * Uses Playwright with the Chromium that is already installed (set CHROMIUM=/path to override).
 */
const { chromium } = require("playwright");
const assert = require("node:assert/strict");

const BASE = process.env.BASE || "http://127.0.0.1:8099";
let passed = 0;
const step = async (name, fn) => {
  try {
    await fn();
    passed++;
    console.log("  ok    " + name);
  } catch (e) {
    console.log("  FAIL  " + name + "\n        " + String(e.message).split("\n").join("\n        "));
    process.exitCode = 1;
  }
};

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined, args: ["--no-sandbox"] });
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => m.type() === "error" && !/Failed to load resource/.test(m.text()) && errors.push(m.text()));
  const requests = [];
  page.on("request", (r) => requests.push({ url: r.url(), method: r.method(), headers: r.headers() }));

  const marker = () => page.evaluate(() => window.__marker);
  const content = () => page.locator("#cast-view");

  await step("first load: the shell is lazy, then the content is fetched and mounted", async () => {
    await page.goto(BASE + "/");
    await page.waitForSelector("#cast-view:not([data-cast-lazy]) .card");
    assert.match(await content().innerText(), /build something amazing/);
    assert.equal(await page.title(), "Cast Starter | Home");
    assert.equal(await page.evaluate(() => document.documentElement.dataset.lastMounted), "home.home");
    await page.evaluate(() => (window.__marker = "alive"));
  });

  await step("the first Cast request carries the Cast headers", async () => {
    const cast = requests.find((r) => r.headers["x-cast-request"] === "1");
    assert.ok(cast, "a Cast request was made");
    assert.equal(cast.headers["x-cast-type"], "partial");
    assert.equal(cast.headers["x-cast-guard"], "public");
  });

  await step("a link to another guard swaps the whole body without a reload", async () => {
    await page.click("text=Log in >> nth=0");
    await page.waitForSelector("input[name=password]");
    assert.equal(await marker(), "alive", "the page was not reloaded");
    assert.match(page.url(), /\/login$/);
    assert.equal(await page.title(), "Cast Starter | Login");
    assert.equal(await page.evaluate(() => document.documentElement.dataset.lastMounted), "auth.login");
  });

  await step("a failed login shows the message in the form (no reload)", async () => {
    await page.fill("input[name=email]", "admin@example.com");
    await page.fill("input[name=password]", "wrong-password");
    await page.click("button[type=submit]");
    await page.waitForFunction(() => document.querySelector("[data-cast-message]").textContent.includes("do not match"));
    assert.equal(await marker(), "alive");
  });

  await step("a successful login follows the redirect envelope and rotates the CSRF token", async () => {
    const before = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]').content);
    await page.fill("input[name=password]", "password");
    await page.click("button[type=submit]");
    await page.waitForURL(/\/items$/);
    await page.waitForSelector("#cast-view:not([data-cast-lazy]) .card");
    assert.match(page.url(), /\/items$/);
    assert.equal(await marker(), "alive");
    assert.ok(await page.locator("text=admin@example.com (log out)").count(), "the nav shows the signed-in user");
    const after = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]').content);
    assert.notEqual(before, after, "token rotated at login");
    assert.equal(after, await page.evaluate(() => Cast.token()));
  });

  await step("a form with validation errors shows them under the fields (422)", async () => {
    await page.fill("#cast-view input[name=name]", "a");
    await page.click("#cast-view button[type=submit]");
    await page.waitForSelector("#cast-view .cast-error-text");
    assert.match(await page.locator("#cast-view .cast-error-text").first().innerText(), /at least 2/i);
  });

  await step("adding an item loads the page once (no second refresh after the redirect)", async () => {
    requests.length = 0;
    await page.fill("#cast-view input[name=name]", "Washer");
    await page.fill("#cast-view input[name=qty]", "3");
    await page.fill("#cast-view input[name=price]", "1");
    await page.click("#cast-view button[type=submit]");
    await page.waitForSelector("#items-table td:text('Washer')");
    await page.waitForTimeout(400);
    const loads = requests.filter((r) => r.method === "GET" && r.url.endsWith("/items") && r.headers["x-cast-request"] === "1");
    assert.equal(loads.length, 1, "page loads after the submit: " + JSON.stringify(requests.map((r) => r.method + " " + r.url + " " + (r.headers["x-cast-type"] || ""))));
  });

  await step("adding an item through a Cast form refreshes the list", async () => {
    await page.fill("#cast-view input[name=name]", "Widget");
    await page.fill("#cast-view input[name=qty]", "5");
    await page.fill("#cast-view input[name=price]", "2.5");
    await page.click("#cast-view button[type=submit]");
    await page.waitForSelector("#items-table td:text('Widget')");
    assert.equal(await marker(), "alive");
  });

  await step("PUT through Cast.http carries the CSRF header and updates the row", async () => {
    await page.click("#items-table tr:has-text('Widget') .js-restock");
    await page.waitForFunction(() => [...document.querySelectorAll("#items-table tr")].some((r) => r.textContent.includes("Widget") && r.children[1].textContent.trim() === "6"));
    const put = requests.filter((r) => r.method === "PUT").pop();
    assert.ok(put.headers["x-csrf-token"], "PUT has X-CSRF-TOKEN");
  });

  await step("a modal opens from a link, saves through a PUT form and closes", async () => {
    await page.click("#items-table tr:has-text('Widget') a[data-cast=modal]");
    await page.waitForSelector("dialog.cast-modal[open] #edit-item-form");
    assert.ok((await page.getAttribute("dialog.cast-modal", "class")).includes("modal-sm"));
    await page.fill("dialog #edit-item-form input[name=qty]", "9");
    await page.click("dialog #edit-item-form button[type=submit]");
    await page.waitForSelector("dialog.cast-modal", { state: "detached" });
    await page.waitForFunction(() => [...document.querySelectorAll("#items-table tr")].some((r) => r.textContent.includes("Widget") && r.children[1].textContent.trim() === "9"));
    assert.equal(await marker(), "alive");
  });

  await step("moving between pages of the same guard swaps only the content and its CSS/JS", async () => {
    assert.ok(await page.locator("link[href*='/css/items/items.css'][data-cast-page]").count(), "items.css is marked as page-level");
    await page.click("nav >> text=Stats");
    await page.waitForSelector("#stat-items");
    assert.equal(await marker(), "alive");
    assert.match(page.url(), /\/stats$/);
    assert.equal(await page.title(), "Cast Starter | Stats");
    assert.equal(await page.locator("link[href*='/css/items/items.css']").count(), 0, "the previous page's CSS is removed");
    assert.equal(await page.locator("link[href*='/css/stats/stats.css']").count(), 1, "the new page's CSS is added once");
    assert.equal(await page.locator("script[src*='items.module.js']").count(), 0);
    assert.equal(await page.locator("#cast-view").getAttribute("data-stats-mounted"), "1");
    assert.ok(await page.locator("header.nav").count(), "the header was kept (partial swap)");
  });

  await step("the content container does not change the layout (cards keep their gap)", async () => {
    const gaps = await page.evaluate(() => {
      const cards = [...document.querySelectorAll("#cast-view .card")];
      return cards.slice(1).map((c, i) => c.getBoundingClientRect().top - cards[i].getBoundingClientRect().bottom);
    });
    assert.ok(gaps.length >= 0 && gaps.every((g) => g >= 8), "gaps between cards: " + gaps);
  });

  await step("after a navigation focus moves to the new content and the change is announced", async () => {
    assert.ok(await page.evaluate(() => !!document.activeElement.closest("#cast-view")), "focus is inside the new content");
    await page.waitForFunction(() => document.getElementById("cast-announcer").textContent.includes("Stats"));
  });

  await step("back and forward restore pages (no reload)", async () => {
    await page.goBack();
    await page.waitForSelector("#items-table");
    assert.match(page.url(), /\/items$/);
    assert.equal(await page.evaluate(() => document.documentElement.dataset.statsLeft), "1", "destroy() ran when leaving Stats");
    assert.equal(await page.locator("link[href*='/css/items/items.css']").count(), 1, "items.css came back");
    assert.equal(await page.locator("link[href*='/css/stats/stats.css']").count(), 0);
    await page.goForward();
    await page.waitForSelector("#stat-items");
    assert.equal(await page.locator("#cast-view").getAttribute("data-stats-mounted"), "2", "mounted again on the second visit");
    assert.equal(await marker(), "alive");
  });

  await step("a request that fails shows an error block, not a blank page", async () => {
    await page.evaluate(() => Cast.load("/definitely-missing"));
    await page.waitForSelector("#cast-view .cast-error");
    assert.equal(await page.locator("#cast-view .cast-error h2").innerText(), "404");
    assert.ok(await page.locator("#cast-view .cast-retry").count(), "a Try again button");
    assert.match(page.url(), /\/stats$/, "the address did not change");
  });

  await step("logging out (a Cast form) returns to the public layout without a reload", async () => {
    await page.click("text=admin@example.com (log out)");
    await page.waitForSelector("text=Log in");
    assert.equal(await marker(), "alive");
    assert.match(page.url(), /\/$/);
    assert.equal(await page.locator("nav >> text=Stats").count(), 0);
  });

  await step("links can opt out with data-cast=off (a full load)", async () => {
    await page.evaluate(() => {
      const a = document.createElement("a");
      a.id = "plain-link";
      a.href = Cast.url("/");
      a.setAttribute("data-cast", "off");
      a.textContent = "plain";
      document.querySelector("header").append(a);
    });
    await page.click("#plain-link");
    await page.waitForSelector("#cast-view:not([data-cast-lazy]) .card");
    assert.equal(await marker(), undefined, "a real page load happened");
  });

  await step("without JavaScript the shell explains what is needed", async () => {
    const plain = await browser.newContext({ javaScriptEnabled: false });
    const p = await plain.newPage();
    await p.goto(BASE + "/");
    assert.match(await p.locator("noscript").first().innerHTML(), /JavaScript is required/);
    await plain.close();
  });

  await step("no JavaScript errors were thrown", async () => {
    assert.deepEqual(errors, []);
  });

  await browser.close();
  console.log(`\n${process.exitCode ? "FAILED" : "all " + passed + " passed"}`);
})();
