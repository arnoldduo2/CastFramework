"use strict";
// Runs once; Cast calls mount() every time the page is shown and destroy() when it is left.
Cast.page({
  mount(ctx) {
    const message = (text, ok = true) => {
      const el = ctx.el.querySelector("#items-message");
      if (!el) return;
      el.textContent = text;
      el.style.color = ok ? "var(--primary-color)" : "var(--danger-color)";
    };
    const rowData = (el) => JSON.parse(el.closest("tr").getAttribute("data-item").replaceAll("'", '"'));
    const refresh = () => Cast.load(location.pathname + location.search, { push: false });

    ctx.on("click", ".js-restock", async (event, button) => {
      const item = rowData(button);
      const res = await Cast.http({ url: `/items/${item.id}`, type: "PUT", data: { name: item.name, qty: item.qty + 1, price: item.price } });
      res.status === "success" ? refresh() : message(res.msg, false);
    });

    ctx.on("click", ".js-delete", async (event, button) => {
      const res = await Cast.http({ url: `/items/${rowData(button).id}`, type: "DELETE" });
      res.status === "success" ? refresh() : message(res.msg, false);
    });
  },
});
