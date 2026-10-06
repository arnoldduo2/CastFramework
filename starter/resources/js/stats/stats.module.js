"use strict";
Cast.page({
  mount(ctx) {
    ctx.el.dataset.statsMounted = String((Number(ctx.el.dataset.statsMounted) || 0) + 1);
  },
  destroy(ctx) {
    document.documentElement.dataset.statsLeft = "1";
  },
});
