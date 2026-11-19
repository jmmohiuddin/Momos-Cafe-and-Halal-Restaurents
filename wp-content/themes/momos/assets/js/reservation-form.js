/**
 * SCR-C09 reservation request form (reservation.md §6.1, screens.md §3.9; F-RES-01, FR-RES-001..005,
 * FR-RES-027/029, BR-RES-01/10). API-01 drives the pickers (dates within the booking window, slots with
 * lead time and closures applied), API-02 receives the request with an Idempotency key generated once
 * per page load (retries reuse it), the honeypot and the Turnstile token.
 * Errors: inline on blur after the first edit and on submit; `res.err.summary` (role=alert) is focused
 * and links to the fields; server field codes map to the same catalog keys; SYSTEM errors show a
 * banner with the branch phone and `res.err.reference`.
 * Success (201, duplicate:false) pushes `generate_lead` once, then the body goes to
 * sessionStorage["momo_last_request"] and the page navigates to C10 (no PII in any URL).
 */
(function (w, d) {
	'use strict';
	var R = w.MomoRes;
	var form = d.getElementById('momo-res-form');
	if (!R || !form) { return; }
	var cfg = R.config();
	var lang = cfg.lang || 'ja';
	var $ = function (s, c) { return (c || form).querySelector(s); };
	var $$ = function (s, c) { return Array.prototype.slice.call((c || form).querySelectorAll(s)); };

	var STATE_KEY = 'momo_res_form_state';
	var RULES_TTL = 5 * 60 * 1000;
	var branches = {};
	(cfg.branches || []).forEach(function (b) { branches[b.code] = b; });

	var st = {
		key: R.uuid(),            // Idempotency-Key: once per form load (API-02 step 6).
		rules: null,              // API-01 body of the selected branch.
		rulesAt: 0,
		want: { date: cfg.prefill && cfg.prefill.date || '', time: cfg.prefill && cfg.prefill.time || '', party: '' },
		touched: {},
		busy: false,
		blocked: false,           // pending_limit_reached / branch not accepting.
		partyCall: false,         // "More than {max}?" chosen → submit disabled (A2).
		failures: 0,
		started: false,
		langExplicit: false,
		captchaId: null,
		captchaToken: '',
		captchaWaiters: []
	};

	var f = {
		branch: $('#res-branch'), date: $('#res-date'), name: $('#res-name'), email: $('#res-email'), phone: $('#res-phone'),
		note: $('#res-note'), lang: $('#res-lang'), consent: $('#res-consent'), special: $('#res-special-consent'), website: $('#res-website'),
		party: $('#res-party')
	};
	var ui = {
		dateChips: $('[data-res-date-chips]'), times: $('[data-res-times]'), timeSkel: $('[data-res-time-skel]'), noTimes: $('[data-res-no-times]'),
		partyChips: $('[data-res-party-chips]'), stepper: $('[data-res-party-stepper]'), more: $('[data-res-party-more]'), partyCall: $('[data-res-party-call]'),
		branchInfo: $('[data-res-branch-info]'), testBanner: $('[data-res-test-banner]'), policy: $('[data-res-policy]'), errsum: $('#res-errsum'),
		banner: $('[data-res-banner]'), submit: $('[data-res-submit]'), submitLabel: $('[data-res-submit-label]'), callAlt: $('[data-res-call-alt]'),
		promise: d.querySelector('[data-res-promise]'), captcha: $('[data-res-captcha]')
	};

	// ---------------------------------------------------------------- helpers
	function branch() { return branches[f.branch.value] || null; }
	function phoneNode(b) {
		var c = st.rules && st.rules.contact;
		if (c && (c.phone_e164 || c.phone_display)) { return R.telLink(c.phone_e164, c.phone_display); }
		return b && b.tel ? R.el('a', { href: b.tel, class: 'momo-link' }, [R.icon('phone', 'momo-icon--sm'), R.bdi(b.phone)]) : null;
	}
	function phoneText(b) {
		var c = st.rules && st.rules.contact;
		if (c && (c.phone_e164 || c.phone_display)) { return R.phoneDisplay(c.phone_e164, c.phone_display); }
		return b ? b.phone : '';
	}
	function selectedTime() { var r = $('input[name="time"]:checked'); return r ? r.value : ''; }
	function selectedParty() {
		if (!ui.stepper.hidden) { var v = parseInt(R.latin(f.party.value), 10); return isNaN(v) ? '' : String(v); }
		var r = $('input[name="party_size"]:checked');
		return r ? r.value : '';
	}
	function dayOf(date) {
		if (!st.rules) { return null; }
		for (var i = 0; i < st.rules.days.length; i++) { if (st.rules.days[i].date === date) { return st.rules.days[i]; } }
		return null;
	}
	/**
	 * Analytics push through datalayer.js `momoPush(event, params, cb)` (allow-listed keys only), or a
	 * plain dataLayer push when it is absent. Never on TEST branches or for logged-in users. `cb` runs
	 * after GTM's eventCallback or ≤ 500 ms; immediately when nothing was pushed.
	 */
	function track(event, params, cb) {
		var b = branch();
		cb = cb || null;
		if (!cfg.analytics || (b && b.is_test)) { if (cb) { cb(); } return; }
		if (typeof w.momoPush === 'function') { w.momoPush(event, params, cb || undefined); return; }
		w.dataLayer = w.dataLayer || [];
		var o = { event: event };
		Object.keys(params).forEach(function (k) { o[k] = params[k]; });
		w.dataLayer.push(o);
		if (cb) { w.setTimeout(cb, w.google_tag_manager ? 500 : 0); }
	}

	// ---------------------------------------------------------------- errors
	var FIELD_CONTROL = { branch: 'res-branch', date: 'res-date', time: 'res-time-group', party: 'res-party-group', name: 'res-name', email: 'res-email', phone: 'res-phone', note: 'res-note', lang: 'res-lang', consent: 'res-consent', special_consent: 'res-special-consent' };
	var errors = {};

	function errorSlot(field) {
		var id = FIELD_CONTROL[field];
		var base = id.replace(/-group$/, '');
		return d.getElementById(base + '-error');
	}
	function focusTarget(field) {
		if (field === 'time') { return $('input[name="time"]') || f.date; }
		if (field === 'party') { return ui.stepper.hidden ? ($('input[name="party_size"]') || ui.more) : f.party; }
		return d.getElementById(FIELD_CONTROL[field]);
	}
	function setError(field, msg) {
		var slot = errorSlot(field);
		if (!slot) { return; }
		var ctrl = d.getElementById(FIELD_CONTROL[field]);
		if (msg) {
			errors[field] = msg;
			slot.querySelector('[data-res-msg]').textContent = msg;
			slot.hidden = false;
			if (ctrl && ctrl.tagName !== 'FIELDSET') { ctrl.setAttribute('aria-invalid', 'true'); }
			if (ctrl && ctrl.tagName === 'FIELDSET') { $$('input', ctrl).forEach(function (i) { i.setAttribute('aria-invalid', 'true'); }); }
		} else {
			delete errors[field];
			slot.hidden = true;
			if (ctrl) { ctrl.removeAttribute('aria-invalid'); }
			if (ctrl && ctrl.tagName === 'FIELDSET') { $$('input', ctrl).forEach(function (i) { i.removeAttribute('aria-invalid'); }); }
		}
	}
	function clearErrors() { Object.keys(FIELD_CONTROL).forEach(function (k) { setError(k, null); }); ui.errsum.hidden = true; }

	function showSummary(noFocus) {
		var keys = Object.keys(FIELD_CONTROL).filter(function (k) { return errors[k]; });
		if (!keys.length) { ui.errsum.hidden = true; return; }
		$('[data-res-errsum-title]', ui.errsum).textContent = R.t('res.err.summary', { n: keys.length });
		var list = $('[data-res-errsum-list]', ui.errsum);
		list.textContent = '';
		keys.forEach(function (k) {
			var target = focusTarget(k);
			var a = R.el('a', { href: '#' + (target && target.id ? target.id : FIELD_CONTROL[k]), text: errors[k] });
			a.addEventListener('click', function (e) { e.preventDefault(); var t = focusTarget(k); if (t) { t.focus(); } });
			list.appendChild(R.el('li', {}, [a]));
		});
		ui.errsum.hidden = false;
		if (!noFocus) { ui.errsum.focus(); }
	}
	/** Keep an open summary in sync while the guest fixes fields (no focus move). */
	function syncSummary() { if (!ui.errsum.hidden) { showSummary(true); } }

	function hideBanner() { ui.banner.hidden = true; }
	function showBanner(msg, requestId, withTel, type) {
		ui.banner.className = 'momo-alert momo-alert--' + (type || 'error');
		$('[data-res-banner-msg]', ui.banner).textContent = msg;
		var ref = $('[data-res-banner-ref]', ui.banner);
		if (requestId) { R.fill(ref, 'res.err.reference', { request_id: R.bdi(requestId) }); ref.hidden = false; } else { ref.hidden = true; }
		var acts = $('[data-res-banner-actions]', ui.banner);
		var tel = $('[data-res-banner-tel]', ui.banner);
		var b = branch();
		var c = st.rules && st.rules.contact;
		var e164 = c && c.phone_e164 ? c.phone_e164 : '';
		if (withTel && (e164 || (b && b.tel))) {
			tel.textContent = '';
			tel.href = e164 ? R.telHref(e164) : b.tel;
			tel.appendChild(R.icon('phone'));
			tel.appendChild(R.bdi(phoneText(b)));
			acts.hidden = false;
		} else {
			acts.hidden = true;
		}
		ui.banner.hidden = false;
	}

	// ---------------------------------------------------------------- validation (client mirror of FieldValidator)
	var CONTROL_CHARS = /[\u0000-\u001F\u007F]/;
	function validate(field) {
		var b = branch();
		var v;
		switch (field) {
			case 'branch':
				if (!f.branch.value) { return R.t('res.err.branch.required'); }
				if (b && !b.accepting || (st.rules && st.rules.accepting === false)) { return R.t('res.err.branch.unavailable', { branch: b ? b.name : '', phone: phoneText(b) }); }
				return null;
			case 'date':
				return f.date.value ? null : R.t('res.err.date.required');
			case 'time':
				return selectedTime() ? null : R.t('res.err.time.required');
			case 'party':
				v = selectedParty();
				if (st.partyCall) { return R.t('res.err.party.max_call', { max: st.rules ? st.rules.rules.party_max : '', branch: b ? b.name : '', phone: phoneText(b) }); }
				if (v === '') { return R.t('res.err.party.required'); }
				if (st.rules && +v < st.rules.rules.party_min) { return R.t('res.err.party.min', { min: st.rules.rules.party_min }); }
				if (st.rules && +v > st.rules.rules.party_max) { return R.t('res.err.party.max_call', { max: st.rules.rules.party_max, branch: b ? b.name : '', phone: phoneText(b) }); }
				return null;
			case 'name':
				v = f.name.value.trim();
				if (!v) { return R.t('res.err.name.required'); }
				if (v.length > 100) { return R.t('res.err.name.length'); }
				if (/[<>]/.test(v) || CONTROL_CHARS.test(v)) { return R.t('res.err.name.format'); }
				return null;
			case 'email':
				v = f.email.value.trim();
				if (!v) { return R.t('res.err.email.required'); }
				return v.length <= 254 && /^[^\s@<>()",;]+@[^\s@<>()",;]+\.[^\s@<>()",;]{2,}$/.test(v) ? null : R.t('res.err.email.format');
			case 'phone':
				v = R.latin(f.phone.value).trim();
				if (!v) { return null; }
				var digits = v.replace(/\D/g, '');
				return /^\+?[0-9\s().\-]+$/.test(v) && digits.length >= 8 && digits.length <= 15 ? null : R.t('res.err.phone.format');
			case 'note':
				return f.note.value.length > 1000 ? R.t('res.err.note.length') : null;
			case 'consent':
				return f.consent.checked ? null : R.t('res.err.consent.required');
			case 'special_consent':
				return !f.special || !f.note.value.trim() || f.special.checked ? null : R.t('res.err.special_consent.required');
			case 'lang':
				return f.lang.value ? null : R.t('res.err.lang');
		}
		return null;
	}
	function validateAll() {
		var ok = true;
		Object.keys(FIELD_CONTROL).forEach(function (k) {
			if (k === 'special_consent' && !f.special) { return; }
			var m = validate(k);
			setError(k, m);
			if (m) { ok = false; }
		});
		return ok;
	}

	// ---------------------------------------------------------------- summary panel (FR-RES-027)
	function sumSet(key, value) {
		var n = form.querySelector('[data-res-sum="' + key + '"]');
		if (!n) { return; }
		n.textContent = '';
		if (value === '' || value === null) { n.appendChild(R.el('span', { class: 'momo-muted', text: R.t('res.summary.empty') })); } else { n.appendChild(value.nodeType ? value : d.createTextNode(value)); }
	}
	function renderSummary() {
		var b = branch();
		sumSet('branch', b ? b.name : '');
		sumSet('date', f.date.value ? R.fmtDate(f.date.value, 'long') : '');
		var tm = selectedTime();
		sumSet('time', tm ? R.timeNode(tm) : '');
		var p = selectedParty();
		sumSet('party', p ? R.bdi(p) : '');
		var em = form.querySelector('[data-res-sum="email"]');
		var e = f.email.value.trim();
		if (e) { R.fill(em, 'res.summary.reply_to', { email: R.el('bdi', { dir: 'ltr', class: 'momo-res-replyto__email', text: e }) }); em.hidden = false; } else { em.hidden = true; }
	}

	// ---------------------------------------------------------------- branch-dependent UI
	function renderBranchUi() {
		var b = branch();
		if (ui.testBanner) { ui.testBanner.hidden = !(b && b.is_test); }
		// Policy link (DEC-054): only when the branch policy page is published in this language.
		if (b && b.policy_url) {
			var a = $('a', ui.policy);
			a.href = b.policy_url;
			$('[data-res-msg]', ui.policy).textContent = R.t('res.policy.link', { branch: b.name });
			ui.policy.hidden = false;
		} else {
			ui.policy.hidden = true;
		}
		var tel = phoneNode(b);
		if (b && tel) { R.fill(ui.callAlt, 'res.call_alt', { branch: b.name, phone: tel }); ui.callAlt.hidden = false; } else { ui.callAlt.hidden = true; }
		var promise = st.rules && st.rules.rules && st.rules.rules.response_promise_minutes;
		if (ui.promise) {
			if (promise) { ui.promise.textContent = R.t('res.submitted.promise', { duration: R.duration(promise) }); ui.promise.hidden = false; } else { ui.promise.hidden = true; }
		}
	}

	function setDisabled(disabled) {
		[f.date, f.name, f.email, f.phone, f.note, f.lang, f.consent, f.party].forEach(function (x) { if (x) { x.disabled = disabled; } });
		$$('input[name="time"], input[name="party_size"], input[name="res-date-chip"]').forEach(function (x) { x.disabled = disabled; });
		updateSubmit();
	}
	function updateSubmit() {
		var off = st.blocked || st.partyCall;
		if (off) { ui.submit.setAttribute('aria-disabled', 'true'); } else if (!st.busy) { ui.submit.removeAttribute('aria-disabled'); }
	}

	function loadRules(keepSelections) {
		var b = branch();
		st.rules = null;
		st.blocked = false;
		ui.dateChips.hidden = true;
		ui.dateChips.textContent = '';
		f.date.disabled = true;
		ui.times.textContent = '';
		ui.noTimes.hidden = true;
		ui.partyChips.textContent = '';
		ui.stepper.hidden = true;
		ui.more.hidden = true;
		ui.partyCall.hidden = true;
		st.partyCall = false;
		renderBranchUi();
		renderSummary();
		if (!b) { updateSubmit(); return; }
		if (!b.accepting) {
			setError('branch', validate('branch'));
			st.blocked = true;
			setDisabled(true);
			return;
		}
		setError('branch', null);
		ui.timeSkel.hidden = false;
		var code = b.code;
		R.request(R.api('branches/' + encodeURIComponent(code) + '/reservation-rules', { lang: lang })).then(function (res) {
			if (f.branch.value !== code) { return; }
			ui.timeSkel.hidden = true;
			if (res.status !== 200 || !res.body || !res.body.days) {
				ui.times.textContent = '';
				var retry = R.el('button', { type: 'button', class: 'momo-btn momo-btn--secondary momo-btn--sm', text: R.t('common.retry') });
				retry.addEventListener('click', function () { loadRules(true); });
				ui.times.appendChild(R.el('p', { class: 'momo-field__help', role: 'status', text: R.t('res.slots.error') }));
				ui.times.appendChild(retry);
				return;
			}
			st.rules = res.body;
			st.rulesAt = Date.now();
			renderBranchUi();
			if (!st.rules.accepting) {
				b.accepting = false;
				setError('branch', validate('branch'));
				st.blocked = true;
				setDisabled(true);
				return;
			}
			setDisabled(false);
			renderDates(keepSelections);
			renderParty();
			renderSummary();
		});
	}

	// ---------------------------------------------------------------- dates
	function renderDates(keep) {
		var days = st.rules.days || [];
		var want = st.want.date || (keep ? f.date.value : '');
		var sel = f.date;
		while (sel.options.length > 1) { sel.remove(1); }
		var group = null, month = '';
		days.forEach(function (day, i) {
			var m = day.date.slice(0, 7);
			if (m !== month) {
				month = m;
				group = R.el('optgroup', { label: R.fmtMonth(day.date) });
				sel.appendChild(group);
			}
			var label = R.fmtDate(day.date, 'long');
			var opt = R.el('option', { value: day.date });
			if (!day.slots.length) {
				opt.disabled = true;
				label = R.t(day.closed_reason ? 'res.date.closed_suffix' : 'res.date.too_soon_suffix', { date: label });
			}
			opt.textContent = label;
			group.appendChild(opt);
			if (i < 2 && day.slots.length) {
				// Today / Tomorrow chips, only when bookable.
				var id = 'res-date-chip-' + i;
				var input = R.el('input', { type: 'radio', name: 'res-date-chip', id: id, value: day.date });
				input.addEventListener('change', function () { f.date.value = day.date; onDateChange(); });
				ui.dateChips.appendChild(R.el('label', { class: 'momo-chip momo-chip--choice', for: id }, [input, R.icon('check', 'momo-icon--sm momo-chip__check'), R.el('span', { text: R.t(i === 0 ? 'res.date.today' : 'res.date.tomorrow') })]));
			}
		});
		ui.dateChips.hidden = !ui.dateChips.children.length;
		sel.disabled = false;
		var d0 = want && dayOf(want);
		sel.value = d0 && d0.slots.length ? want : '';
		st.want.date = '';
		syncDateChips();
		renderTimes();
	}
	function syncDateChips() {
		$$('input[name="res-date-chip"]').forEach(function (c) { c.checked = c.value === f.date.value; });
	}
	function onDateChange() {
		syncDateChips();
		renderTimes();
		touch('date');
		setError('date', validate('date'));
		if (errors.time && !selectedTime()) { setError('time', null); }
		syncSummary();
		renderSummary();
	}

	// ---------------------------------------------------------------- times (chips grouped by period)
	function renderTimes() {
		var prev = st.want.time || selectedTime();
		ui.times.textContent = '';
		ui.noTimes.hidden = true;
		var day = dayOf(f.date.value);
		if (!day) { renderSummary(); return; }
		if (!day.slots.length) { ui.noTimes.hidden = false; renderSummary(); return; }
		var groups = [], byLabel = {};
		day.slots.forEach(function (s) {
			var label = (day.periods && day.periods[s]) || 'all_day';
			if (!byLabel[label]) { byLabel[label] = []; groups.push(label); }
			byLabel[label].push(s);
		});
		var n = 0;
		groups.forEach(function (label) {
			var chips = R.el('div', { class: 'momo-chips' });
			byLabel[label].forEach(function (s) {
				var id = 'res-time-' + (n++);
				var input = R.el('input', { type: 'radio', name: 'time', id: id, value: s });
				if (s === prev) { input.checked = true; }
				chips.appendChild(R.el('label', { class: 'momo-chip momo-chip--choice', for: id }, [input, R.icon('check', 'momo-icon--sm momo-chip__check'), R.bdi(R.fmtTime(s))]));
			});
			var wrap = R.el('div', { class: 'momo-res-period' });
			if (groups.length > 1) { wrap.appendChild(R.el('p', { class: 'momo-res-period__label', text: R.t('period.' + label) })); }
			wrap.appendChild(chips);
			ui.times.appendChild(wrap);
		});
		st.want.time = '';
		renderSummary();
	}

	// ---------------------------------------------------------------- party
	function renderParty() {
		var r = st.rules.rules;
		var want = st.want.party || selectedParty();
		ui.partyChips.textContent = '';
		if (r.party_max <= 8) {
			for (var p = r.party_min; p <= r.party_max; p++) {
				var id = 'res-party-' + p;
				var input = R.el('input', { type: 'radio', name: 'party_size', id: id, value: String(p) });
				if (String(p) === String(want)) { input.checked = true; }
				ui.partyChips.appendChild(R.el('label', { class: 'momo-chip momo-chip--choice', for: id }, [input, R.icon('check', 'momo-icon--sm momo-chip__check'), R.bdi(String(p))]));
			}
			ui.stepper.hidden = true;
			ui.partyChips.hidden = false;
		} else {
			ui.partyChips.hidden = true;
			ui.stepper.hidden = false;
			f.party.value = want && +want >= r.party_min && +want <= r.party_max ? want : '';
		}
		ui.more.textContent = R.t('res.party.more', { max: r.party_max });
		ui.more.hidden = false;
		ui.more.setAttribute('aria-expanded', 'false');
		ui.partyCall.hidden = true;
		st.partyCall = false;
		st.want.party = '';
		updateSubmit();
	}
	function setPartyCall(on) {
		var b = branch();
		st.partyCall = on;
		ui.more.setAttribute('aria-expanded', on ? 'true' : 'false');
		if (on) {
			$$('input[name="party_size"]').forEach(function (i) { i.checked = false; });
			R.fill($('[data-res-msg]', ui.partyCall), 'res.err.party.max_call', { max: st.rules.rules.party_max, branch: b ? b.name : '', phone: phoneNode(b) || '' });
			ui.partyCall.hidden = false;
		} else {
			ui.partyCall.hidden = true;
			setError('party', null);
		}
		updateSubmit();
		renderSummary();
	}

	// ---------------------------------------------------------------- captcha (Turnstile; test mode offline)
	function captchaInit() {
		var c = cfg.captcha || {};
		if (c.mode !== 'live' || !c.siteKey || !ui.captcha) { return; }
		var tries = 0;
		(function wait() {
			if (!w.turnstile || typeof w.turnstile.render !== 'function') {
				if (++tries < 100) { w.setTimeout(wait, 100); }
				return;
			}
			st.captchaId = w.turnstile.render(ui.captcha, {
				sitekey: c.siteKey,
				action: c.action || 'reservation_submit',
				language: ['ja', 'en', 'ar'].indexOf(lang) >= 0 ? lang : 'auto',
				appearance: 'interaction-only',
				callback: function (token) { st.captchaToken = token; st.captchaWaiters.splice(0).forEach(function (fn) { fn(token); }); },
				'expired-callback': function () { st.captchaToken = ''; },
				'error-callback': function () { st.captchaToken = ''; }
			});
		}());
	}
	function captchaToken() {
		var c = cfg.captcha || {};
		if (c.mode !== 'live') { return Promise.resolve(w.momoCaptchaTestToken || 'test-pass'); }
		if (st.captchaToken) { return Promise.resolve(st.captchaToken); }
		return new Promise(function (resolve) {
			st.captchaWaiters.push(resolve);
			w.setTimeout(function () { resolve(st.captchaToken || ''); }, 15000);
		});
	}
	function captchaReset() {
		st.captchaToken = '';
		if (st.captchaId !== null && w.turnstile && typeof w.turnstile.reset === 'function') { w.turnstile.reset(st.captchaId); }
	}

	// ---------------------------------------------------------------- server errors
	var REASON_KEY = {
		branch: { required: 'res.err.branch.required', '*': 'res.err.branch.not_found' },
		date: { required: 'res.err.date.required', '*': 'res.err.date.required' },
		time: { required: 'res.err.time.required', '*': 'res.err.time.unavailable' },
		party: { required: 'res.err.party.required', '*': 'res.err.party.required' },
		name: { required: 'res.err.name.required', too_long: 'res.err.name.length', invalid_format: 'res.err.name.format', '*': 'res.err.name.required' },
		email: { required: 'res.err.email.required', '*': 'res.err.email.format' },
		phone: { '*': 'res.err.phone.format' },
		note: { '*': 'res.err.note.length' },
		consent: { '*': 'res.err.consent.required' },
		special_consent: { '*': 'res.err.special_consent.required' },
		lang: { '*': 'res.err.lang' }
	};
	var API_FIELD = { branch_code: 'branch', date: 'date', time: 'time', party_size: 'party', name: 'name', email: 'email', phone: 'phone', special_request: 'note', consent: 'consent', special_request_consent: 'special_consent', preferred_lang: 'lang' };

	function handleError(res) {
		var body = res.body || {}, data = body.data || {}, code = body.code || '', b = branch();
		var fields = data.fields || {};
		var msg = body.message || '';
		switch (code) {
			case 'invalid_param':
				var mapped = 0;
				Object.keys(fields).forEach(function (k) {
					var local = API_FIELD[k];
					if (!local) { return; }
					var map = REASON_KEY[local] || {};
					setError(local, R.t(map[fields[k]] || map['*'], { days: st.rules ? st.rules.rules.booking_window_days : '' }));
					mapped++;
				});
				if (fields.idempotency_key) { st.key = R.uuid(); }
				if (!mapped) { return systemError(data.request_id); }
				return showSummary();
			case 'captcha_failed':
			case 'origin_rejected':
				captchaReset();
				return showBanner(msg || R.t('res.err.captcha'), null, false);
			case 'branch_not_found':
				setError('branch', R.t('res.err.branch.not_found'));
				return showSummary();
			case 'slot_unavailable':
				if (fields.time === 'branch_closed_on_date') {
					setError('date', R.t('res.err.date.closed', { branch: b ? b.name : '' }));
					st.want.party = selectedParty();
					f.date.value = '';
				} else {
					setError('time', R.t('res.err.time.unavailable'));
					st.want.date = f.date.value;
					st.want.party = selectedParty();
				}
				loadRules(true);
				return showSummary();
			case 'pending_limit_reached':
				st.blocked = true;
				updateSubmit();
				return showBanner(msg, null, true, 'warning');
			case 'branch_not_accepting':
				if (b) { b.accepting = false; }
				showBanner(msg, null, true, 'warning');
				st.blocked = true;
				setDisabled(true);
				return;
			case 'party_size_out_of_range':
				setError('party', msg);
				return showSummary();
			case 'lead_time_not_met':
				setError('time', msg);
				st.want.date = f.date.value;
				st.want.party = selectedParty();
				loadRules(true);
				return showSummary();
			case 'outside_booking_window':
				setError('date', msg);
				return showSummary();
			case 'rate_limited':
				return showBanner(msg || R.t('res.err.rate', { phone: phoneText(b) }), null, true);
		}
		return systemError(data.request_id);
	}
	function systemError(requestId) {
		st.failures++;
		// Retry keeps the same Idempotency key; a second failure shows the phone prominently.
		showBanner(R.t('res.err.system', { phone: phoneText(branch()) }), requestId || null, st.failures >= 2);
	}

	// ---------------------------------------------------------------- submit
	function setBusy(on) {
		st.busy = on;
		ui.submit.disabled = on;
		if (on) { ui.submit.setAttribute('aria-busy', 'true'); ui.submitLabel.textContent = R.t('res.submit.busy'); } else { ui.submit.removeAttribute('aria-busy'); ui.submitLabel.textContent = R.t('res.submit'); }
		updateSubmit();
	}
	function bandOf(p) { p = +p; return p <= 2 ? '1-2' : (p <= 6 ? '3-6' : '7+'); }

	function onSuccess(res) {
		var b = branch();
		var body = res.body;
		body.ui_lang = lang;
		R.session.set('momo_last_request', JSON.stringify(body));
		R.session.del(STATE_KEY);
		var go = function () { w.location.assign(cfg.submittedUrl); };
		if (res.status === 201 && body.duplicate === false) {
			var done = false, once = function () { if (!done) { done = true; go(); } };
			track('generate_lead', { branch_code: b ? b.code : '', language: lang, party_size_band: bandOf(selectedParty()), lead_source: 'reservation_form' }, once);
			w.setTimeout(once, 600); // Never strand the guest on the form.
			return;
		}
		go();
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (st.busy) { return; }
		hideBanner();
		if (st.blocked || st.partyCall) { if (st.partyCall) { setError('party', validate('party')); showSummary(); } return; }
		if (!validateAll()) { showSummary(); return; }
		ui.errsum.hidden = true;
		setBusy(true);
		captchaToken().then(function (token) {
			if (!token) { setBusy(false); showBanner(R.t('res.err.captcha'), null, false); return null; }
			var body = {
				branch_code: f.branch.value,
				date: f.date.value,
				time: selectedTime(),
				party_size: parseInt(selectedParty(), 10),
				name: f.name.value.trim(),
				email: f.email.value.trim(),
				phone: R.latin(f.phone.value).trim() || null,
				special_request: f.note.value.trim() || null,
				lang: lang,
				preferred_lang: f.lang.value || lang,
				consent: f.consent.checked === true,
				idempotency_key: st.key,
				captcha_token: token,
				website: f.website ? f.website.value : ''
			};
			if (cfg.separateConsent && f.special) { body.special_request_consent = f.special.checked; }
			return R.request(R.api('reservations'), { method: 'POST', body: body }).then(function (res) {
				if ((res.status === 201 || res.status === 200) && res.body && res.body.reference) {
					onSuccess(res);
					return;
				}
				setBusy(false);
				handleError(res);
			});
		});
	});

	// ---------------------------------------------------------------- events
	function touch(field) { st.touched[field] = true; }
	function blurValidate(field) { if (st.touched[field] || errors[field]) { setError(field, validate(field)); syncSummary(); } }

	f.branch.addEventListener('change', function () {
		var had = f.date.value || selectedTime();
		st.want = { date: '', time: '', party: '' };
		f.date.value = '';
		clearErrors();
		hideBanner();
		ui.branchInfo.textContent = had ? R.t('res.info.branch_changed') : '';
		touch('branch');
		loadRules(false);
	});
	f.date.addEventListener('focus', function () {
		if (st.rules && Date.now() - st.rulesAt > RULES_TTL) { st.want.date = f.date.value; st.want.time = selectedTime(); st.want.party = selectedParty(); loadRules(true); }
	});
	f.date.addEventListener('change', onDateChange);
	form.addEventListener('change', function (e) {
		var t = e.target;
		if (t.name === 'time') { touch('time'); setError('time', null); syncSummary(); renderSummary(); }
		if (t.name === 'party_size') { touch('party'); if (st.partyCall) { setPartyCall(false); } setError('party', null); syncSummary(); renderSummary(); }
		if (t === f.lang) { st.langExplicit = true; }
		if (t === f.consent) { touch('consent'); blurValidate('consent'); }
		if (t === f.special) { touch('special_consent'); blurValidate('special_consent'); }
		if (!st.started && t !== f.website) {
			st.started = true;
			var b = branch(), p = { language: lang };
			if (b && !b.is_test) { p.branch_code = b.code; }
			track('reservation_form_start', p);
		}
	});
	form.addEventListener('input', function (e) {
		var t = e.target;
		if (t === f.name) { touch('name'); }
		if (t === f.email) { touch('email'); renderSummary(); }
		if (t === f.phone) { touch('phone'); }
		if (t === f.note) { touch('note'); }
		var live = { 'res-name': 'name', 'res-email': 'email', 'res-phone': 'phone', 'res-note': 'note' }[t.id];
		if (live && errors[live] && !validate(live)) { setError(live, null); syncSummary(); }
		if (!st.started && (t === f.name || t === f.email || t === f.phone || t === f.note)) {
			st.started = true;
			var b = branch(), p = { language: lang };
			if (b && !b.is_test) { p.branch_code = b.code; }
			track('reservation_form_start', p);
		}
	});
	form.addEventListener('focusout', function (e) {
		var map = { 'res-name': 'name', 'res-email': 'email', 'res-phone': 'phone', 'res-note': 'note', 'res-branch': 'branch', 'res-date': 'date' };
		var k = map[e.target.id];
		if (k) { blurValidate(k); }
		if (e.target === f.party) { touch('party'); renderSummary(); blurValidate('party'); }
	});
	ui.stepper.addEventListener('click', function (e) {
		var btn = e.target.closest('button[data-step]');
		if (!btn || !st.rules) { return; }
		var r = st.rules.rules, v = parseInt(R.latin(f.party.value), 10);
		v = isNaN(v) ? r.party_min : v + (+btn.getAttribute('data-step'));
		f.party.value = String(Math.max(r.party_min, Math.min(r.party_max, v)));
		touch('party');
		setError('party', null);
		if (st.partyCall) { setPartyCall(false); }
		renderSummary();
	});
	ui.more.addEventListener('click', function () { setPartyCall(!st.partyCall); });

	// Keep values across a language switch (Doc 07 §4.13 row 4): consent is never restored; the reply
	// language follows the new page language unless it was changed explicitly.
	d.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[data-momo-event="select_language"]');
		if (!a) { return; }
		var data = {
			branch: f.branch.value, date: f.date.value, time: selectedTime(), party: selectedParty(),
			name: f.name.value, email: f.email.value, phone: f.phone.value, note: f.note.value,
			preferred_lang: st.langExplicit ? f.lang.value : ''
		};
		var filled = data.name || data.email || data.phone || data.note || data.branch;
		if (!R.session.set(STATE_KEY, JSON.stringify(data)) && filled && !w.confirm(R.t('res.info.entries_cleared'))) {
			e.preventDefault();
		}
	}, true);

	function restore() {
		var raw = R.session.get(STATE_KEY);
		if (!raw) { return false; }
		R.session.del(STATE_KEY);
		var s;
		try { s = JSON.parse(raw); } catch (e) { return false; }
		if (!s || typeof s !== 'object') { return false; }
		['name', 'email', 'phone', 'note'].forEach(function (k) { if (typeof s[k] === 'string') { f[k].value = s[k]; } });
		if (s.preferred_lang && f.lang.querySelector('option[value="' + s.preferred_lang + '"]')) { f.lang.value = s.preferred_lang; st.langExplicit = true; }
		if (s.branch && branches[s.branch]) {
			f.branch.value = s.branch;
			st.want = { date: s.date || '', time: s.time || '', party: s.party || '' };
		}
		return true;
	}

	// ---------------------------------------------------------------- init
	form.hidden = false;
	ui.submit.disabled = false; // Server-rendered disabled until the script runs (no native submit).
	restore();
	if (f.branch.value && !branches[f.branch.value]) { f.branch.value = ''; }
	captchaInit();
	renderBranchUi();
	renderSummary();
	if (f.branch.value) { loadRules(true); }
}(window, document));
