/* cast:components:start */
(function (root) {
'use strict';
var Cast = {
on: function (event, selector, handler) {
document.addEventListener(event, function (e) {
var el = e.target && e.target.closest ? e.target.closest(selector) : null;
if (el) {
handler.call(el, e, el);
}
});
},
update: function (target, data) {
var el = typeof target === 'string' ? document.querySelector(target) : target;
if (!el) return;
if (el.tagName === 'SELECT' && Array.isArray(data)) {
var frag = document.createDocumentFragment();
data.forEach(function (opt) {
var option = document.createElement('option');
option.value = opt.value !== undefined ? opt.value : opt;
option.textContent = opt.label !== undefined ? opt.label : opt;
if (opt.selected) option.selected = true;
frag.appendChild(option);
});
el.innerHTML = '';
el.appendChild(frag);
el.dispatchEvent(new Event('change', { bubbles: true }));
} else if (typeof data === 'string') {
el.innerHTML = data;
}
},
refresh: function (target, endpoint, options) {
var el = typeof target === 'string' ? document.querySelector(target) : target;
if (!el) return Promise.reject(new Error('Target not found'));
options = options || {};
options.headers = options.headers || {};
options.headers['X-Cast-Refresh'] = '1';
return fetch(endpoint, options)
.then(function (res) {
if (!res.ok) throw new Error('Refresh request failed: ' + res.status);
return res.text();
})
.then(function (html) {
var temp = document.createElement('div');
temp.innerHTML = html.trim();
var newEl = temp.firstElementChild;
if (newEl && el.parentNode) {
el.parentNode.replaceChild(newEl, el);
Cast.triggerLoad(newEl);
}
return newEl;
});
},
_loadCallbacks: [],
onLoad: function (callback) {
Cast._loadCallbacks.push(callback);
},
triggerLoad: function (container) {
var rootEl = container || document;
Cast._loadCallbacks.forEach(function (cb) {
try { cb(rootEl); } catch (err) { console.error(err); }
});
document.dispatchEvent(new CustomEvent('cast:load', { detail: { container: rootEl } }));
}
};
root.handleEvent = function (event, selector, handler) {
Cast.on(event, selector, handler);
};
root.Cast = Cast;
if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', function () { Cast.triggerLoad(document); });
} else {
Cast.triggerLoad(document);
}
})(typeof window !== 'undefined' ? window : this);
/* cast:components:end */
