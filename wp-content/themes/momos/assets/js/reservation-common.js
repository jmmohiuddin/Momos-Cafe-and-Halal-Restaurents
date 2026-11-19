/**
 * Shared helpers of the customer reservation screens C09–C11 (reservation.md §6, §8.5).
 * - Catalog strings come from the page config (`#momo-res-config`, rendered by momos-core); placeholders
 *   are filled here, values are inserted as text nodes (never innerHTML) and numbers/phones/references
 *   are wrapped in `<bdi dir="ltr">` (FR-I18N-005).
 * - Dates/times mirror Momos\Presentation\Format\DateFormatter: Gregorian, Asia/Tokyo, 24 h, Western
 *   digits (`-u-ca-gregory-nu-latn`, bn override); ja/en literal patterns, others CLDR via Intl.
 * - Phones mirror PhoneFormatter: national in ja, +81 international elsewhere; `tel:` in E.164.
 * No personal data is ever logged, sent to analytics or put into a URL here.
 */
(function (w, d) {
	'use strict';
	var cfg = null;

	function config() {
		if (cfg) { return cfg; }
		var el = d.getElementById('momo-res-config');
		try { cfg = el ? JSON.parse(el.textContent) : {}; } catch (e) { cfg = {}; }
		cfg.i18n = cfg.i18n || {};
		return cfg;
	}

	function raw(key) {
		var v = config().i18n[key];
		return typeof v === 'string' ? v : key;
	}

	/** Plain-text string with {placeholders} filled. */
	function t(key, args) {
		return raw(key).replace(/\{([a-z_]+)\}/g, function (m, k) {
			return args && Object.prototype.hasOwnProperty.call(args, k) ? String(args[k] && args[k].nodeType ? args[k].textContent : args[k]) : '';
		});
	}

	/** Fill an element with a catalog string; Node-valued args are inserted as nodes, strings as text. */
	function fill(el, key, args) {
		el.textContent = '';
		var parts = raw(key).split(/(\{[a-z_]+\})/);
		parts.forEach(function (p) {
			var m = /^\{([a-z_]+)\}$/.exec(p);
			if (m) {
				var v = args && Object.prototype.hasOwnProperty.call(args, m[1]) ? args[m[1]] : '';
				el.appendChild(v && v.nodeType ? v : d.createTextNode(String(v)));
			} else if (p) {
				el.appendChild(d.createTextNode(p));
			}
		});
		return el;
	}

	/** Element factory: attrs (text → textContent, class, hidden, data-*, aria-*), children (nodes/strings). */
	function el(tag, attrs, children) {
		var n = d.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'text') { n.textContent = String(v); } else if (k === 'class') { n.className = v; } else { n.setAttribute(k, v === true ? '' : String(v)); }
		});
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			n.appendChild(c.nodeType ? c : d.createTextNode(String(c)));
		});
		return n;
	}

	function bdi(text, dir) {
		return el('bdi', { dir: dir || 'ltr', text: text });
	}

	function icon(name, cls) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'momo-icon' + (cls ? ' ' + cls : ''));
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var use = d.createElementNS(ns, 'use');
		use.setAttribute('href', (config().sprite || '') + '#i-' + name);
		svg.appendChild(use);
		return svg;
	}

	// ---- Digits / dates / times ------------------------------------------------------------
	var DIG = [0x660, 0x6F0, 0x966, 0x9E6, 0xFF10];
	function latin(s) {
		return String(s).replace(/[٠-٩۰-۹०-९০-৯０-９]/g, function (c) {
			var cc = c.charCodeAt(0);
			for (var k = 0; k < DIG.length; k++) { if (cc >= DIG[k] && cc <= DIG[k] + 9) { return String(cc - DIG[k]); } }
			return c;
		});
	}

	function ymdDate(ymd) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
		return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12)) : null;
	}

	/** Long/short date of a JST business date 'YYYY-MM-DD' (DateFormatter::long/short). */
	function fmtDate(ymd, kind, lang) {
		lang = lang || config().lang || 'ja';
		var dt = ymdDate(ymd);
		if (!dt) { return ymd || ''; }
		if (lang === 'ja') {
			var wd = ['日', '月', '火', '水', '木', '金', '土'][dt.getUTCDay()];
			return kind === 'short' ? (dt.getUTCMonth() + 1) + '/' + dt.getUTCDate() + '（' + wd + '）'
				: dt.getUTCFullYear() + '年' + (dt.getUTCMonth() + 1) + '月' + dt.getUTCDate() + '日（' + wd + '）';
		}
		try {
			if (lang === 'en') {
				var parts = {};
				new Intl.DateTimeFormat('en-GB-u-ca-gregory-nu-latn', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
					.formatToParts(dt).forEach(function (p) { parts[p.type] = p.value; });
				return parts.weekday + ' ' + parts.day + ' ' + parts.month + (kind === 'short' ? '' : ' ' + parts.year);
			}
			var opts = kind === 'short' ? { weekday: 'short', day: 'numeric', month: 'short' } : { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
			opts.timeZone = 'UTC';
			return latin(new Intl.DateTimeFormat(lang + '-u-ca-gregory-nu-latn', opts).format(dt));
		} catch (e) {
			return ymd;
		}
	}

	/** Month heading for the date <optgroup> ("December 2026" / "2026年12月"). */
	function fmtMonth(ymd, lang) {
		lang = lang || config().lang || 'ja';
		var dt = ymdDate(ymd);
		if (!dt) { return ''; }
		if (lang === 'ja') { return dt.getUTCFullYear() + '年' + (dt.getUTCMonth() + 1) + '月'; }
		try {
			return latin(new Intl.DateTimeFormat((lang === 'en' ? 'en-GB' : lang) + '-u-ca-gregory-nu-latn', { timeZone: 'UTC', month: 'long', year: 'numeric' }).format(dt));
		} catch (e) {
			return ymd.slice(0, 7);
		}
	}

	/** "19:00" → "19:00", "09:30" → "9:30" (DateFormatter::time, 24 h, Western digits). */
	function fmtTime(hm) {
		var m = /^(\d{1,2}):(\d{2})/.exec(hm || '');
		return m ? parseInt(m[1], 10) + ':' + m[2] : (hm || '');
	}

	/** Time with the JST label in non-ja languages (DEC-062). */
	function fmtTimeZone(hm, lang) {
		lang = lang || config().lang || 'ja';
		return lang === 'ja' ? fmtTime(hm) : (fmtTime(hm) + ' ' + raw('common.tz_label')).trim();
	}

	/** Time node: the digits in an LTR isolate, the zone label as text (correct order in RTL). */
	function timeNode(hm, lang) {
		lang = lang || config().lang || 'ja';
		var span = el('span', {}, [bdi(fmtTime(hm))]);
		if (lang !== 'ja') { span.appendChild(d.createTextNode(' ' + raw('common.tz_label'))); }
		return span;
	}

	/** ISO instant (+09:00) → {date:'YYYY-MM-DD', time:'HH:MM'} in JST. */
	function jstParts(iso) {
		var t = Date.parse(iso || '');
		if (isNaN(t)) { return null; }
		var j = new Date(t + 9 * 3600 * 1000);
		var p = function (n) { return (n < 10 ? '0' : '') + n; };
		return { date: j.getUTCFullYear() + '-' + p(j.getUTCMonth() + 1) + '-' + p(j.getUTCDate()), time: p(j.getUTCHours()) + ':' + p(j.getUTCMinutes()) };
	}

	/** Minutes → "{n} minutes" / "{n} hours" (Notification Renderer::duration mirror). */
	function duration(min) {
		min = parseInt(min, 10) || 0;
		if (min >= 60 && min % 60 === 0) {
			var h = min / 60;
			return t(h === 1 ? 'email.duration.hour' : 'email.duration.hours', { n: h });
		}
		return t('email.duration.minutes', { n: min });
	}

	// ---- Phones / references ----------------------------------------------------------------
	function phoneDisplay(e164, national, lang) {
		lang = lang || config().lang || 'ja';
		e164 = e164 || ''; national = national || '';
		if (lang === 'ja' && national) { return national; }
		if (national && national.charAt(0) === '0' && e164.indexOf('+81') === 0) { return '+81 ' + national.slice(1); }
		return e164 || national;
	}

	function telHref(e164) {
		return 'tel:' + String(e164 || '').replace(/[^0-9+]/g, '');
	}

	/** `tel:` link with the phone in an LTR isolate. */
	function telLink(e164, national, cls) {
		if (!e164 && !national) { return null; }
		var a = el('a', { href: telHref(e164 || national), class: cls || 'momo-link' }, [icon('phone', 'momo-icon--sm'), bdi(phoneDisplay(e164, national))]);
		return a;
	}

	/** Spoken reference: characters in groups ("7 Q 2 K, X 9 M D"). */
	function refAria(display) {
		return String(display || '').split('-').map(function (g) { return g.split('').join(' '); }).join(', ');
	}

	// ---- Misc ---------------------------------------------------------------------------------
	function uuid() {
		if (w.crypto && typeof w.crypto.randomUUID === 'function') { return w.crypto.randomUUID(); }
		var b = new Uint8Array(16);
		if (w.crypto && w.crypto.getRandomValues) { w.crypto.getRandomValues(b); } else { for (var i = 0; i < 16; i++) { b[i] = Math.floor(Math.random() * 256); } }
		b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
		var h = Array.prototype.map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}

	var session = {
		get: function (k) { try { return w.sessionStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { w.sessionStorage.setItem(k, v); return true; } catch (e) { return false; } },
		del: function (k) { try { w.sessionStorage.removeItem(k); } catch (e) { /* noop */ } }
	};

	/** Endpoint URL under the REST root (works with `/wp-json/` and `?rest_route=`), query params appended. */
	function api(route, query) {
		var base = config().api || '';
		var url = base + route;
		var q = Object.keys(query || {}).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&');
		return q ? url + (url.indexOf('?') >= 0 ? '&' : '?') + q : url;
	}

	/** JSON request; resolves {status, body, headers} (network errors → status 0). Never logs bodies. */
	function request(url, opts) {
		opts = opts || {};
		var headers = { Accept: 'application/json' };
		Object.keys(opts.headers || {}).forEach(function (k) { headers[k] = opts.headers[k]; });
		var init = { method: opts.method || 'GET', headers: headers, credentials: opts.credentials || 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer' };
		if (opts.body !== undefined) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return w.fetch(url, init).then(function (res) {
			return res.text().then(function (txt) {
				var body = null;
				try { body = txt ? JSON.parse(txt) : null; } catch (e) { body = null; }
				return { status: res.status, body: body, headers: res.headers };
			});
		}, function () { return { status: 0, body: null, headers: null }; });
	}

	function announce(text) {
		var live = d.querySelector('[data-res-live]');
		if (live) { live.textContent = ''; w.setTimeout(function () { live.textContent = text; }, 50); }
	}

	w.MomoRes = {
		config: config, t: t, raw: raw, fill: fill, el: el, bdi: bdi, icon: icon, latin: latin,
		fmtDate: fmtDate, fmtMonth: fmtMonth, fmtTime: fmtTime, fmtTimeZone: fmtTimeZone, timeNode: timeNode, jstParts: jstParts, duration: duration,
		phoneDisplay: phoneDisplay, telHref: telHref, telLink: telLink, refAria: refAria, uuid: uuid, session: session,
		request: request, api: api, announce: announce
	};
}(window, document));
