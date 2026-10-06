"use strict";
$(function () {
  const message = (text, ok = true) =>
    $("#items-message").text(text).css("color", ok ? "var(--primary-color)" : "var(--danger-color)");

  const rowData = (el) => JSON.parse($(el).closest("tr").attr("data-item").replaceAll("'", '"'));

  $("#items-table").on("click", ".js-restock", async function () {
    const item = rowData(this);
    const res = await app.request(`/items/${item.id}`, { method: "PUT", data: { name: item.name, qty: item.qty + 1, price: item.price } });
    res.status === "success" ? location.reload() : message(res.msg, false);
  });

  $("#items-table").on("click", ".js-delete", async function () {
    const item = rowData(this);
    const res = await app.request(`/items/${item.id}`, { method: "DELETE" });
    res.status === "success" ? location.reload() : message(res.msg, false);
  });
});
