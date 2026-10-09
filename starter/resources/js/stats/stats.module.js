"use strict";
// This page's script (resources/js/stats/stats.module.js): see items.module.js for what Cast.page gives you.
Cast.page({
  mount(ctx) {
    ctx.el.dataset.statsMounted = String((Number(ctx.el.dataset.statsMounted) || 0) + 1);
  },
  destroy(ctx) {
    document.documentElement.dataset.statsLeft = "1";
  },
});
