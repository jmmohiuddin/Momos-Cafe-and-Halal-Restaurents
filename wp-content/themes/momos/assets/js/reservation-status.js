/**
 * SCR-C11 status & cancel (reservation.md §6.3, screens.md §3.11; FR-RES-019/020, DEC-058/064/066).
 * 1. Read the `#t=` fragment, 2. remove it with history.replaceState, 3. keep it in
 * sessionStorage["momo_status_token:{REF}"] (the language switcher re-attaches it as `#t=`, never as a
 * query), 4. call API-03 with header X-Momo-Token (never in a URL, body or log).
 * 404/410 → `status.link_invalid` without data. Cancel: inline panel (not a modal) with focus
 * management → API-04; 409 reloads and shows `status.cancel.not_allowed` as INFO.
 * Customers see the given name only (Ruling C-8) and never attendance (completed/no_show = confirmed +
 * `status.past`, done by the API). No analytics, no third-party requests.
 */
(function (w, d) {
	'use strict';
	var R = w.MomoRes;
	var root = d.querySelector('[data-momo-res="status"]');
	if (!R || !root) { return; }
	var cfg = R.config();
	var ref = cfg.ref;
	var lang = cfg.lang || 'ja';
	var el = R.el, t = R.t;
	var TOKEN_RE = /^[A-Za-z0-9_-]{43}$/;
	var KEY = 'momo_status_token:' + ref;
	var out = root.querySelector('[data-res-result]');
	var skel = root.querySelector('[data-res-skeleton]');
	var invalid = root.querySelector('[data-res-invalid]');
	var heading = root.querySelector('[data-res-heading]');

	// Steps 1–3: fragment → sessionStorage; the address bar keeps no token.
	var token = '';
	var m = /(?:^#|&)t=([^&]+)/.exec(w.location.hash || '');
	if (m) {
		try { token = decodeURIComponent(m[1]); } catch (e) { token = ''; }
		if (w.history && typeof w.history.replaceState === 'function') { w.history.replaceState(null, '', w.location.pathname + w.location.search); }
		if (TOKEN_RE.test(token)) { R.session.set(KEY, token); }
	}
	if (!TOKEN_RE.test(token)) { token = R.session.get(KEY) || ''; }

	// Language switcher: re-attach the token as a fragment (never a query parameter).
	d.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[data-momo-event="select_language"]');
		if (a && token && ref && a.href.indexOf('#') < 0) { a.href = a.href + '#t=' + encodeURIComponent(token); }
	}, true);

	function showInvalid() {
		skel.hidden = true;
		out.hidden = true;
		out.textContent = '';
		invalid.hidden = false;
		heading.textContent = t('status.title');
	}
	if (!ref || !TOKEN_RE.test(token)) { showInvalid(); return; }

	function api(method) {
		var path = 'reservations/' + encodeURIComponent(ref);
		return R.request(method === 'POST' ? R.api(path + '/cancel') : R.api(path, { lang: lang }), {
			method: method,
			headers: { 'X-Momo-Token': token },
			body: method === 'POST' ? { lang: lang } : undefined,
			credentials: 'omit'
		});
	}

	var ICON = { pending: 'hourglass', confirmed: 'check-circle', rejected: 'x-circle', cancelled: 'slash-circle', expired: 'clock-x' };
	var view = null;

	function row(label, value) { return el('div', {}, [el('dt', { text: label }), el('dd', {}, [value])]); }

	function render(data, info) {
		view = data;
		var st = data.status;
		var b = data.branch || {};
		out.textContent = '';

		// H1 badge: icon + label text (status is never colour only) + one-sentence meaning.
		heading.textContent = '';
		heading.className = 'momo-title momo-status momo-status--block momo-status--' + st;
		heading.appendChild(R.icon(ICON[st] || 'info'));
		heading.appendChild(el('span', { text: data.status_label || t('status.' + st) }));
		var meaning = [el('p', { class: 'momo-lead', text: t('status.meaning.' + st) })];
		if (data.status_note) { meaning.push(el('p', { class: 'momo-alert momo-alert--info', text: data.status_note })); }
		if (st === 'cancelled' && data.cancelled_by) { meaning.push(el('p', { text: t('status.cancelled_by.' + data.cancelled_by) })); }
		out.appendChild(el('div', { class: 'momo-stack' }, meaning));
		if (info) { out.appendChild(el('div', { class: 'momo-alert momo-alert--info', role: 'status' }, [R.icon('info'), el('p', {}, [info])])); }

		// Reference + details.
		var code = el('bdi', { dir: 'ltr', class: 'momo-res-ref__code', 'aria-label': t('res.ref.aria', { ref: R.refAria(data.reference_display) }), text: data.reference_display });
		var dl = el('dl', { class: 'momo-res-dl' }, [
			row(t('res.label.branch'), el('strong', { text: b.name || '' })),
			row(t('res.label.date'), d.createTextNode(R.fmtDate(data.date, 'long'))),
			row(t('res.label.time'), R.timeNode(data.time)),
			row(t('res.label.party'), R.bdi(String(data.party_size))),
			row(t('res.label.name'), el('bdi', { text: data.guest_given_name || '' }))
		]);
		out.appendChild(el('section', { class: 'momo-card momo-stack', 'aria-labelledby': 'res-det-h' }, [
			el('h2', { class: 'momo-h3', id: 'res-det-h', text: t('status.details') }),
			R.fill(el('p', { class: 'momo-res-ref' }), 'res.submitted.reference', { ref: code }),
			dl
		]));

		// Message from the branch (sent as written, any script).
		if (data.message_from_branch) {
			out.appendChild(el('section', { class: 'momo-card momo-card--tint momo-stack', 'aria-labelledby': 'res-msg-h' }, [
				el('h2', { class: 'momo-h3', id: 'res-msg-h', text: t('status.message_from_branch') }),
				el('p', { dir: 'auto', class: 'momo-res-message', text: data.message_from_branch })
			]));
		}

		// Declined: reason + suggested times (not held) linking to C09 prefilled.
		if (st === 'rejected') {
			var dec = el('section', { class: 'momo-stack' });
			if (data.decline_reason_label) { dec.appendChild(el('p', { class: 'momo-strong', text: data.decline_reason_label })); }
			if (data.suggested_times && data.suggested_times.length) {
				dec.appendChild(el('p', { text: t('decline.suggested') }));
				var ul = el('ul', { class: 'momo-list-reset momo-stack' });
				data.suggested_times.forEach(function (s) {
					var q = '?branch=' + encodeURIComponent(b.code || '') + '&date=' + encodeURIComponent(s.date) + '&time=' + encodeURIComponent(s.time);
					ul.appendChild(el('li', { class: 'momo-res-suggest' }, [
						el('span', {}, [d.createTextNode(R.fmtDate(s.date, 'long') + ' '), R.timeNode(s.time)]),
						el('a', { class: 'momo-btn momo-btn--secondary momo-btn--sm', href: cfg.formUrl + q, text: t('status.request_another') })
					]));
				});
				dec.appendChild(ul);
			}
			out.appendChild(dec);
		}

		// Cancel (only when allowed).
		if (data.can_cancel) { out.appendChild(cancelSection(data)); }

		// History (no staff identities, no notes).
		if (data.history && data.history.length) {
			var ol = el('ol', { class: 'momo-res-timeline' });
			data.history.forEach(function (h) {
				var p = R.jstParts(h.at);
				ol.appendChild(el('li', {}, [
					el('span', { class: 'momo-strong', text: t('status.' + h.status) }),
					p ? el('span', { class: 'momo-small momo-muted' }, [d.createTextNode(R.fmtDate(p.date, 'short') + ' '), R.timeNode(p.time)]) : null
				]));
			});
			out.appendChild(el('section', { class: 'momo-stack', 'aria-labelledby': 'res-hist-h' }, [el('h2', { class: 'momo-h3', id: 'res-hist-h', text: t('status.history') }), ol]));
		}

		// Branch: address (localised + Japanese), phone, directions; policy.
		var binfo = el('section', { class: 'momo-card momo-stack', 'aria-labelledby': 'res-br-h' }, [el('h2', { class: 'momo-h3', id: 'res-br-h', text: t('status.branch_info') }), el('p', { class: 'momo-strong', text: b.name || '' })]);
		if (b.address) { binfo.appendChild(el('p', { dir: 'auto', text: b.address })); }
		if (b.address_ja && b.address_ja !== b.address && lang !== 'ja') { binfo.appendChild(el('p', { lang: 'ja', dir: 'ltr', class: 'momo-small momo-muted', text: b.address_ja })); }
		var acts = el('div', { class: 'momo-cluster' });
		var tel = R.telLink(b.phone_e164, b.phone_display, 'momo-btn momo-btn--secondary');
		if (tel) { tel.insertBefore(el('span', { class: 'momo-visually-hidden', text: t('cta.call') + ' ' + (b.name || '') + ' ' }), tel.lastChild); acts.appendChild(tel); }
		if (b.directions_url) {
			acts.appendChild(el('a', { class: 'momo-btn momo-btn--secondary', href: b.directions_url, target: '_blank', rel: 'noopener noreferrer' }, [R.icon('directions'), el('span', { text: t('cta.directions') }), el('span', { class: 'momo-visually-hidden', text: ' ' + t('a11y.new_tab') })]));
		}
		if (acts.children.length) { binfo.appendChild(acts); }
		if (cfg.resPolicyUrl) {
			binfo.appendChild(el('p', { class: 'momo-small' }, [el('a', { href: cfg.resPolicyUrl, target: '_blank', rel: 'noopener' }, [el('span', { text: t('res.policy.title') }), el('span', { class: 'momo-visually-hidden', text: ' ' + t('a11y.new_tab') })])]));
		}
		out.appendChild(binfo);

		skel.hidden = true;
		invalid.hidden = true;
		out.hidden = false;
	}

	function cancelSection(data) {
		var cta = el('button', { type: 'button', class: 'momo-btn momo-btn--danger', 'aria-expanded': 'false', 'aria-controls': 'res-cancel-panel', text: t(data.status === 'confirmed' ? 'status.cancel.cta.confirmed' : 'status.cancel.cta.pending') });
		var question = el('p', { id: 'res-cancel-q', class: 'momo-strong', tabindex: '-1', text: t('status.cancel.confirm', { date: R.fmtDate(data.date, 'long'), time: R.fmtTimeZone(data.time) }) });
		var keep = el('button', { type: 'button', class: 'momo-btn momo-btn--secondary', text: t('status.cancel.keep') });
		var yes = el('button', { type: 'button', class: 'momo-btn momo-btn--danger', text: t('status.cancel.yes') });
		var panel = el('div', { id: 'res-cancel-panel', class: 'momo-card momo-res-cancel momo-stack', role: 'group', 'aria-labelledby': 'res-cancel-q', hidden: true }, [question, el('div', { class: 'momo-cluster' }, [yes, keep])]);
		var sec = el('section', { class: 'momo-stack' }, [cta, panel]);
		var deadline = R.jstParts(data.cancel_deadline);
		if (deadline) {
			var when = el('span', {}, [deadline.date === data.date ? '' : R.fmtDate(deadline.date, 'short') + ' ', R.timeNode(deadline.time)]);
			sec.insertBefore(R.fill(el('p', { class: 'momo-small momo-muted' }), 'status.cancel.deadline', { time: when }), panel);
		}
		cta.addEventListener('click', function () {
			panel.hidden = false;
			cta.setAttribute('aria-expanded', 'true');
			question.focus();
		});
		keep.addEventListener('click', function () {
			panel.hidden = true;
			cta.setAttribute('aria-expanded', 'false');
			cta.focus();
		});
		panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { keep.click(); } });
		yes.addEventListener('click', function () {
			if (yes.getAttribute('aria-busy') === 'true') { return; }
			yes.setAttribute('aria-busy', 'true');
			yes.disabled = true;
			keep.disabled = true;
			api('POST').then(function (res) {
				if (res.status === 200 && res.body && res.body.status) {
					render(res.body, R.fill(el('span'), 'status.cancel.done', { phone: telOf(res.body) }));
					R.announce(res.body.status_label || t('status.cancelled'));
					heading.focus();
					return;
				}
				if (res.status === 404 || res.status === 410) { showInvalid(); return; }
				if (res.status === 409) {
					// Reload and explain (INFO): the request changed or the cutoff passed.
					load(R.fill(el('span'), 'status.cancel.not_allowed', { phone: telOf(view) }));
					return;
				}
				yes.removeAttribute('aria-busy');
				yes.disabled = false;
				keep.disabled = false;
				var msg = res.body && res.body.message ? res.body.message : t('status.error');
				var err = panel.querySelector('[data-res-cancel-err]') || panel.appendChild(el('p', { class: 'momo-field__error', role: 'alert', 'data-res-cancel-err': '' }));
				err.textContent = msg;
			});
		});
		return sec;
	}

	function telOf(data) {
		var b = (data && data.branch) || {};
		return R.telLink(b.phone_e164, b.phone_display) || '';
	}

	function load(info) {
		skel.hidden = false;
		api('GET').then(function (res) {
			if (res.status === 200 && res.body && res.body.status) {
				render(res.body, info || null);
				if (info) { heading.focus(); }
				return;
			}
			if (res.status === 400 || res.status === 404 || res.status === 410) { showInvalid(); return; }
			skel.hidden = true;
			out.textContent = '';
			var retry = el('button', { type: 'button', class: 'momo-btn momo-btn--secondary', text: t('common.retry') });
			retry.addEventListener('click', function () { load(); });
			out.appendChild(el('div', { class: 'momo-alert momo-alert--error', role: 'alert' }, [R.icon('alert-circle'), el('div', { class: 'momo-stack' }, [el('p', { text: res.body && res.body.message ? res.body.message : t('status.error') }), el('p', {}, [retry])])]));
			out.hidden = false;
		});
	}

	load();
}(window, document));
