/*! momos frame.js — header, disclosures, mobile menu, action bar, language suggestion, toast, small widgets.
 * Vanilla, deferred, no dependencies (ui-design-system.md §2 budget ≤ 10 KB). Server HTML works without it. */
(function () {
	'use strict';
	var d = document, w = window, root = d.documentElement;
	var $ = function (s, c) { return (c || d).querySelector(s); };
	var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };
	var reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function setCookie(name, value) {
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		d.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
	}
	function getCookie(name) {
		var m = d.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : null;
	}
	function store(key, val) { try { if (val === undefined) { return w.localStorage.getItem(key); } w.localStorage.setItem(key, val); } catch (e) { return null; } return null; }

	/* ---- Toast (CMP-StatusMessage): role=status region in footer, 4 s, pauses on hover/focus ---- */
	var toastEl = $('[data-momo-toast]'), toastTimer = null;
	function toast(msg) {
		if (!toastEl) { return; }
		toastEl.textContent = '';
		w.clearTimeout(toastTimer);
		w.setTimeout(function () { toastEl.textContent = msg; arm(); }, 50);
	}
	function arm() { w.clearTimeout(toastTimer); toastTimer = w.setTimeout(function () { toastEl.textContent = ''; }, 4000); }
	if (toastEl) {
		toastEl.addEventListener('mouseenter', function () { w.clearTimeout(toastTimer); });
		toastEl.addEventListener('mouseleave', arm);
	}
	w.momoToast = toast;

	/* ---- Sticky header shadow (IntersectionObserver on a sentinel) ---- */
	var header = $('[data-momo-header]');
	if (header && 'IntersectionObserver' in w) {
		var sentinel = d.createElement('div');
		sentinel.setAttribute('aria-hidden', 'true');
		sentinel.style.cssText = 'position:absolute;inset-block-start:0;block-size:1px;inline-size:1px';
		d.body.insertBefore(sentinel, d.body.firstChild);
		new IntersectionObserver(function (e) { header.classList.toggle('is-scrolled', !e[0].isIntersecting); }).observe(sentinel);
	}

	/* ---- Disclosures (<details data-momo-disclosure>): one open, Esc / outside click / focus leaving close ---- */
	var discs = $$('details[data-momo-disclosure]');
	function closeAll(except) { discs.forEach(function (x) { if (x !== except) { x.open = false; } }); }
	discs.forEach(function (det) {
		det.addEventListener('toggle', function () { if (det.open) { closeAll(det); } });
		det.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && det.open) { det.open = false; $('summary', det).focus(); }
		});
		det.addEventListener('focusout', function (e) {
			if (det.open && e.relatedTarget && !det.contains(e.relatedTarget)) { det.open = false; }
		});
	});
	d.addEventListener('click', function (e) { discs.forEach(function (x) { if (x.open && !x.contains(e.target)) { x.open = false; } }); });

	/* ---- Dialogs: mobile menu + generic [data-momo-dialog] ---- */
	function openDialog(dlg, opener) {
		if (!dlg || typeof dlg.showModal !== 'function') { return false; }
		dlg._opener = opener || d.activeElement;
		dlg.showModal();
		if (opener) { opener.setAttribute('aria-expanded', 'true'); }
		var h = $('[tabindex="-1"]', dlg);
		if (h && !dlg.hasAttribute('data-momo-mmenu')) { h.focus(); }
		return true;
	}
	function wireDialog(dlg) {
		dlg.addEventListener('close', function () {
			var o = dlg._opener;
			if (o) { o.setAttribute('aria-expanded', 'false'); if (o.focus) { o.focus(); } }
		});
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg) { dlg.close(); return; } // scrim
			if (e.target.closest('[data-momo-mmenu-close],[data-momo-dialog-close]')) { dlg.close(); return; }
			var a = e.target.closest('a[href]');
			if (a && dlg.hasAttribute('data-momo-mmenu')) { dlg._opener = null; dlg.close(); }
		});
	}
	$$('dialog').forEach(wireDialog);
	var mmenu = $('[data-momo-mmenu]');
	$$('[data-momo-menu-open]').forEach(function (btn) {
		if (!mmenu || typeof mmenu.showModal !== 'function') { return; } // no <dialog>: link to footer nav stays
		btn.setAttribute('role', 'button');
		btn.setAttribute('aria-haspopup', 'dialog');
		btn.setAttribute('aria-expanded', 'false');
		btn.addEventListener('click', function (e) { e.preventDefault(); openDialog(mmenu, btn); });
		btn.addEventListener('keydown', function (e) { if (e.key === ' ') { e.preventDefault(); openDialog(mmenu, btn); } });
	});
	d.addEventListener('click', function (e) {
		var o = e.target.closest('[data-momo-dialog-open]');
		if (o && openDialog(d.getElementById(o.getAttribute('data-momo-dialog-open')), o)) { e.preventDefault(); }
	});
	w.addEventListener('resize', function () { if (mmenu && mmenu.open && w.innerWidth >= 1024) { mmenu.close(); } });

	/* ---- Mobile action bar: hidden while a text field has focus or the keyboard is open ---- */
	var bar = $('[data-momo-bar]');
	if (bar) {
		var textual = function (el) {
			return el && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || (el.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|range|color|file|image)$/.test(el.type)));
		};
		var update = function () {
			var kb = w.visualViewport ? w.visualViewport.height < 0.75 * w.innerHeight : false;
			bar.classList.toggle('is-hidden', textual(d.activeElement) || kb);
		};
		d.addEventListener('focusin', update);
		d.addEventListener('focusout', function () { w.setTimeout(update, 0); });
		if (w.visualViewport) { w.visualViewport.addEventListener('resize', update); }
	}

	/* ---- Language switcher: remember the explicit choice (FR-I18N-002/003) ---- */
	d.addEventListener('click', function (e) {
		var a = e.target.closest('a[data-momo-event="select_language"]');
		if (a) { setCookie('momo_lang_choice', a.getAttribute('data-momo-to')); }
	});

	/* ---- Language suggestion bar (client-side only, never redirects) ---- */
	var bar2 = $('[data-momo-langbar]'), data = $('#momo-lang-data');
	if (bar2 && data && !getCookie('momo_lang_choice')) {
		var cfg = null;
		try { cfg = JSON.parse(data.textContent); } catch (e) { cfg = null; }
		var langs = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || ''];
		var best = null;
		if (cfg) {
			for (var i = 0; i < langs.length; i++) {
				var code = String(langs[i] || '').toLowerCase().split('-')[0];
				if (code === cfg.page) { break; }
				if (cfg.langs[code]) { best = code; break; }
			}
		}
		if (best) {
			var L = cfg.langs[best];
			bar2.setAttribute('lang', best);
			bar2.setAttribute('dir', L.dir);
			bar2.setAttribute('aria-label', L.suggest);
			$('[data-momo-langbar-text]', bar2).textContent = L.suggest;
			var sw = $('[data-momo-langbar-switch]', bar2);
			sw.textContent = L['switch'];
			sw.href = L.url;
			sw.setAttribute('hreflang', best);
			sw.addEventListener('click', function () { setCookie('momo_lang_choice', best); });
			var x = $('[data-momo-langbar-dismiss]', bar2);
			x.setAttribute('aria-label', L.dismiss);
			x.addEventListener('click', function () { setCookie('momo_lang_choice', cfg.page); bar2.hidden = true; });
			bar2.hidden = false;
		}
	}

	/* ---- Notices: scheduled reveal (cache-safe), dismiss, "More" on mobile ---- */
	var now = Date.now();
	$$('[data-momo-notice]').forEach(function (n) {
		var s = parseInt(n.getAttribute('data-start') || '0', 10), en = parseInt(n.getAttribute('data-end') || '0', 10);
		if (s && s > now) { n.hidden = true; w.setTimeout(function () { n.hidden = false; }, Math.min(s - now, 2147483000)); }
		if (en && en <= now) { n.hidden = true; }
		if (n.getAttribute('data-momo-notice') === 'dismissible' && store('momo_notice_' + n.id) === '1') { n.hidden = true; }
	});
	d.addEventListener('click', function (e) {
		var x = e.target.closest('[data-momo-notice-dismiss]');
		if (x) { var n = x.closest('[data-momo-notice]'); n.hidden = true; store('momo_notice_' + n.id, '1'); var m = $('#main'); if (m) { m.focus(); } return; }
		var more = e.target.closest('[data-momo-notice-more]');
		if (more) {
			var box = more.closest('[data-momo-notice]'), open = box.classList.toggle('is-expanded');
			more.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (!more._label) { more._label = more.textContent; }
			more.textContent = open ? more.getAttribute('data-less') : more._label;
		}
	});

	/* ---- Copy buttons (hidden without Clipboard API) ---- */
	$$('[data-momo-copy]').forEach(function (b) {
		if (!navigator.clipboard) { b.hidden = true; return; }
		b.addEventListener('click', function () {
			navigator.clipboard.writeText(b.getAttribute('data-momo-copy')).then(function () { toast(b.getAttribute('data-momo-copied') || ''); });
		});
	});
	d.addEventListener('click', function (e) {
		var t = e.target.closest('[data-momo-toast-msg]');
		if (t) { toast(t.getAttribute('data-momo-toast-msg')); }
		var r = e.target.closest('[data-momo-reload]');
		if (r) { e.preventDefault(); location.reload(); }
	});

	/* ---- Native-digit normalisation (Kernel Digits mirror) + stepper ---- */
	var DIG = [0x660, 0x6F0, 0x966, 0x9E6, 0xFF10];
	function normDigits(s) {
		return s.replace(/[٠-٩۰-۹०-९০-৯０-９]/g, function (c) {
			var cc = c.charCodeAt(0);
			for (var k = 0; k < DIG.length; k++) { if (cc >= DIG[k] && cc <= DIG[k] + 9) { return String(cc - DIG[k]); } }
			return c;
		});
	}
	d.addEventListener('focusout', function (e) {
		var el = e.target;
		if (el && el.tagName === 'INPUT' && (el.inputMode === 'numeric' || el.type === 'tel')) { el.value = normDigits(el.value); }
	});
	$$('[data-momo-stepper]').forEach(function (st) {
		var inp = $('input', st), min = +inp.getAttribute('data-min'), max = +inp.getAttribute('data-max');
		var sync = function () {
			var v = parseInt(normDigits(inp.value), 10); v = isNaN(v) ? min : Math.max(min, Math.min(max, v)); inp.value = v;
			$$('button', st).forEach(function (b) {
				var lim = +b.getAttribute('data-step') < 0 ? v <= min : v >= max;
				if (lim) { b.setAttribute('aria-disabled', 'true'); } else { b.removeAttribute('aria-disabled'); }
			});
		};
		st.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-step]');
			if (b && b.getAttribute('aria-disabled') !== 'true') { inp.value = (parseInt(inp.value, 10) || min) + (+b.getAttribute('data-step')); sync(); }
		});
		inp.addEventListener('change', sync);
	});

	/* ---- Character counters (shown from 90 % of the limit) ---- */
	$$('[data-momo-counter-for]').forEach(function (c) {
		var f = d.getElementById(c.getAttribute('data-momo-counter-for')), max = +f.getAttribute('data-momo-counter');
		var up = function () {
			var n = f.value.length;
			c.hidden = n < 0.9 * max;
			c.textContent = c.getAttribute('data-template').replace('{n}', n).replace('{max}', max);
		};
		f.addEventListener('input', up); up();
	});

	/* ---- Allergen "only contains / may contain" toggle; "Show all conditions" clamp ---- */
	d.addEventListener('change', function (e) {
		var t = e.target.getAttribute && e.target.getAttribute('data-momo-allergen-toggle');
		if (t) { var tb = d.getElementById(t); if (tb) { tb.classList.toggle('is-relevant-only', e.target.checked); } }
	});
	$$('[data-momo-clamp]').forEach(function (p) {
		var btn = p.parentNode.querySelector('[data-momo-clamp-toggle]');
		if (btn && p.scrollHeight > p.clientHeight + 2) {
			btn.hidden = false;
			btn.addEventListener('click', function () {
				var open = p.classList.toggle('momo-clamp-3') === false;
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}
	});

	/* ---- Focus the error summary when present ---- */
	var es = $('[data-momo-errsum]');
	if (es) { es.focus(); }

	root.classList.add('momo-js');
	if (reduce) { root.classList.add('momo-reduced-motion'); }
}());
