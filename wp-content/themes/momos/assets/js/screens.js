/*! momos screens.js — screen widgets for SCR-C01…C22 (TASK 2): branch selector auto-submit, menu filters,
 * FAQ accordion, offer/gallery filter chips, analytics opt-out toggle, busy submit. Vanilla, deferred,
 * no dependencies. Every widget is progressive: server HTML works without JS. */
(function () {
	'use strict';
	var d = document, w = window;
	var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };
	var fmt = function (tpl, n) { return String(tpl || '').replace('{n}', String(n)); };
	var setParam = function (url, key, values) {
		url.searchParams.delete(key); url.searchParams.delete(key + '[]');
		(values || []).forEach(function (v) { if (v !== '') { url.searchParams.append(key, v); } });
	};
	var replaceUrl = function (url) { try { w.history.replaceState(null, '', url.pathname + url.search + url.hash); } catch (e) { /* noop */ } };

	// Branch selector
	try {
		var y = w.sessionStorage.getItem('momo_scroll');
		if (y) { w.sessionStorage.removeItem('momo_scroll'); w.scrollTo(0, parseInt(y, 10) || 0); }
	} catch (e) { /* noop */ }
	$$('form[data-momo-autosubmit]').forEach(function (f) {
		var sel = f.querySelector('select');
		if (!sel) { return; }
		sel.addEventListener('change', function () {
			try { w.sessionStorage.setItem('momo_scroll', String(w.scrollY)); } catch (e) { /* noop */ }
			var url = new URL(f.action, location.href);
			new URL(location.href).searchParams.forEach(function (v, k) { if (k !== 'branch') { url.searchParams.append(k, v); } });
			if (sel.value) { url.searchParams.set('branch', sel.value); } else { url.searchParams.delete('branch'); }
			location.href = url.toString();
		});
	});

	// Menu filters
	var form = d.querySelector('[data-momo-filter-form]');
	var list = d.querySelector('[data-momo-dish-list]');
	if (form && list) {
		var det = form.closest('details');
		var count = d.querySelector('[data-momo-result-count]');
		var empty = d.querySelector('[data-momo-filter-empty]');
		var label = d.querySelector('[data-momo-filter-label]');
		if (det && w.matchMedia('(min-width: 1024px)').matches) { det.open = true; }
		var apply = function (push) {
			var diet = $$('input[name="diet[]"]:checked', form).map(function (i) { return i.value; });
			var spEl = form.querySelector('input[name="max_spice"]:checked');
			var sp = spEl && spEl.value !== '' ? parseInt(spEl.value, 10) : null;
			var ex = $$('input[name="exclude[]"]:checked', form).map(function (i) { return i.value; });
			var shown = 0;
			$$(':scope > li', list).forEach(function (li) {
				var card = li.querySelector('[data-tags]') || li.firstElementChild;
				var tags = (card && card.getAttribute('data-tags') || '').split(' ').filter(Boolean);
				var spice = parseInt(card && card.getAttribute('data-spice') || '0', 10);
				var alg = (card && card.getAttribute('data-alg') || '').split(' ').filter(Boolean);
				var ok = diet.every(function (t) { return tags.indexOf(t) !== -1; })
					&& (sp === null || spice <= sp)
					&& (!ex.length || (alg.indexOf('review') === -1 && !ex.some(function (a) { return alg.indexOf(a) !== -1; })));
				li.hidden = !ok;
				if (ok) { shown++; }
			});
			if (count) { count.textContent = fmt(shown === 1 ? count.getAttribute('data-one') : count.getAttribute('data-other'), shown); }
			if (empty) { empty.hidden = shown > 0; }
			var n = diet.length + (sp !== null ? 1 : 0) + ex.length;
			if (label) { label.textContent = fmt(label.getAttribute('data-template'), n); }
			if (push) {
				var url = new URL(location.href);
				setParam(url, 'diet', diet); setParam(url, 'exclude', ex);
				if (sp === null) { url.searchParams.delete('max_spice'); } else { url.searchParams.set('max_spice', String(sp)); }
				replaceUrl(url);
			}
		};
		form.addEventListener('change', function () { apply(true); });
		form.addEventListener('submit', function (e) { e.preventDefault(); apply(true); });
		var clr = form.querySelector('[data-momo-filter-clear]');
		if (clr) {
			clr.addEventListener('click', function (e) {
				e.preventDefault();
				$$('input[type="checkbox"]', form).forEach(function (i) { i.checked = false; });
				var any = form.querySelector('input[name="max_spice"][value=""]'); if (any) { any.checked = true; }
				apply(true);
			});
		}
		if (empty) {
			var eb = empty.querySelector('a');
			if (eb && clr) { eb.addEventListener('click', function (e) { e.preventDefault(); clr.click(); }); }
		}
	}

	// FAQ accordion
	$$('[data-momo-accordion]').forEach(function (acc) {
		var group = acc.getAttribute('data-group') || '';
		$$('.momo-accordion__btn', acc).forEach(function (btn) {
			var panel = d.getElementById(btn.getAttribute('aria-controls'));
			if (!panel) { return; }
			var item = btn.closest('.momo-accordion__item');
			var open = location.hash && item && ('#' + item.id) === location.hash;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			panel.hidden = !open;
			btn.addEventListener('click', function () {
				var now = btn.getAttribute('aria-expanded') !== 'true';
				btn.setAttribute('aria-expanded', now ? 'true' : 'false');
				panel.hidden = !now;
				if (now && group === 'halal_dietary') {
					d.dispatchEvent(new CustomEvent('momo:halal_faq_expand', { detail: { source_page: 'faq' } })); // §12.2 hook for datalayer.js
				}
			});
		});
	});
	w.addEventListener('hashchange', function () {
		var t = location.hash ? d.querySelector(location.hash + '.momo-accordion__item .momo-accordion__btn') : null;
		if (t && t.getAttribute('aria-expanded') !== 'true') { t.click(); }
	});

	// Offers branch chips
	var of = d.querySelector('[data-momo-offer-filter]'), ol = d.querySelector('[data-momo-offer-list]');
	if (of && ol) {
		var oc = d.querySelector('.momo-offers [data-momo-result-count]');
		var showOffers = function (code) {
			var n = 0;
			$$(':scope > li', ol).forEach(function (li) {
				var s = (li.getAttribute('data-scope') || 'all').split(' ');
				var ok = !code || s[0] === 'all' || s.indexOf(code) !== -1;
				li.hidden = !ok; if (ok) { n++; }
			});
			$$('a[data-branch]', of).forEach(function (a) { if (a.getAttribute('data-branch') === code) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); } });
			if (oc) { oc.textContent = fmt(n === 1 ? oc.getAttribute('data-one') : oc.getAttribute('data-other'), n); }
		};
		of.addEventListener('click', function (e) {
			var a = e.target.closest('a[data-branch]');
			if (!a) { return; }
			e.preventDefault();
			var code = a.getAttribute('data-branch');
			var url = new URL(location.href);
			if (code) { url.searchParams.set('branch', code); } else { url.searchParams.delete('branch'); }
			replaceUrl(url); showOffers(code);
		});
	}

	// Gallery filter chips
	var gf = d.querySelector('[data-momo-gallery-filter]'), gl = d.querySelector('[data-momo-gallery]');
	if (gf && gl) {
		gf.hidden = false;
		gf.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-filter]');
			if (!b) { return; }
			var k = b.getAttribute('data-filter');
			$$('button[data-filter]', gf).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			$$(':scope > li', gl).forEach(function (li) { li.hidden = !!k && (li.getAttribute('data-keys') || '').split(' ').indexOf(k) === -1; });
		});
	}

	// Analytics opt-out
	var oo = d.querySelector('[data-momo-optout]');
	if (oo) {
		var btn = oo.querySelector('.momo-switch'), state = oo.querySelector('.momo-switch__text');
		var status = oo.querySelector('[data-momo-optout-status]'), gpc = navigator.globalPrivacyControl === true;
		var off = function () { return /(?:^|; )momo_analytics_optout=1/.test(d.cookie); };
		var render = function () {
			var on = !off() && !gpc;
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			state.textContent = btn.getAttribute(on ? 'data-on' : 'data-off');
		};
		var host = location.hostname.replace(/^www\./, '');
		var killGa = function () {
			d.cookie.split('; ').forEach(function (c) {
				var name = c.split('=')[0];
				if (/^_ga(_|$)/.test(name)) {
					['', '; domain=' + host, '; domain=.' + host].forEach(function (dm) { d.cookie = name + '=; Max-Age=0; Path=/' + dm; });
				}
			});
		};
		oo.querySelector('[data-momo-optout-ui]').hidden = false;
		if (gpc) { oo.querySelector('[data-momo-optout-gpc]').hidden = false; }
		render();
		btn.addEventListener('click', function () {
			var turningOff = btn.getAttribute('aria-pressed') === 'true';
			var secure = location.protocol === 'https:' ? '; Secure' : '';
			if (turningOff) {
				d.cookie = 'momo_analytics_optout=1; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
				killGa();
				if (typeof w.gtag === 'function') { w.gtag('consent', 'update', { analytics_storage: 'denied' }); }
			} else {
				d.cookie = 'momo_analytics_optout=; Max-Age=0; Path=/; SameSite=Lax' + secure;
				if (typeof w.gtag === 'function' && !gpc) { w.gtag('consent', 'update', { analytics_storage: 'granted' }); }
				d.dispatchEvent(new CustomEvent('momo:analytics_optin'));
			}
			render();
			status.textContent = btn.getAttribute(turningOff ? 'data-done-off' : 'data-done-on');
		});
	}

	// Busy state on form submit
	$$('form[data-momo-busy-form]').forEach(function (f) {
		f.addEventListener('submit', function () {
			var b = f.querySelector('button[type="submit"]');
			if (!b || b.getAttribute('aria-busy') === 'true') { return; }
			b.setAttribute('aria-busy', 'true');
			var s = b.querySelector('span'); if (s && b.getAttribute('data-busy-label')) { s.textContent = b.getAttribute('data-busy-label'); }
		});
	});
}());
