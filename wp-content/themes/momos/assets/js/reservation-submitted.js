/**
 * SCR-C10 "Request received — not yet confirmed" (reservation.md §6.2, screens.md §3.10; FR-RES-006,
 * DEC-066). Renders the API-02 body stored by the form in sessionStorage["momo_last_request"] (kept
 * until the tab closes, so a refresh shows the same content). Makes no request, loads nothing else,
 * pushes no analytics. Without stored data the server-rendered generic text stays (no redirect).
 * Never: .ics / "add to calendar", booking/confirmed wording (BR-RES-01).
 */
(function (w, d) {
	'use strict';
	var R = w.MomoRes;
	var root = d.querySelector('[data-momo-res="submitted"]');
	if (!R || !root) { return; }
	var raw = R.session.get('momo_last_request');
	var data = null;
	try { data = raw ? JSON.parse(raw) : null; } catch (e) { data = null; }
	if (!data || !data.reference_display || !data.summary) { return; }

	var el = R.el, t = R.t;
	var lang = R.config().lang || 'ja';
	var s = data.summary;
	var out = root.querySelector('[data-res-result]');
	var heading = root.querySelector('[data-res-heading]');

	// 1. Heading (duplicate variant: res.info.duplicate with the reference).
	if (data.duplicate) {
		R.fill(heading, 'res.info.duplicate', { ref: R.bdi(data.reference_display) });
	}

	// 2. Reference + Copy.
	var refCode = el('bdi', { dir: 'ltr', class: 'momo-res-ref__code', 'aria-label': t('res.ref.aria', { ref: R.refAria(data.reference_display) }), text: data.reference_display });
	var refLine = R.fill(el('p', { class: 'momo-res-ref' }), 'res.submitted.reference', { ref: refCode });
	var refBox = el('div', { class: 'momo-card momo-res-refbox' }, [refLine]);
	if (w.navigator.clipboard) {
		var copy = el('button', { type: 'button', class: 'momo-btn momo-btn--secondary momo-btn--sm' }, [R.icon('copy', 'momo-icon--sm'), el('span', { text: t('res.copy') })]);
		copy.addEventListener('click', function () {
			w.navigator.clipboard.writeText(data.reference_display).then(function () { R.announce(t('res.copied')); }, function () { /* clipboard denied: nothing to announce */ });
		});
		refBox.appendChild(copy);
	}
	out.appendChild(refBox);

	// 3. Summary.
	var row = function (label, value) { return el('div', {}, [el('dt', { text: label }), el('dd', {}, [value])]); };
	var addr = el('span', {}, [el('span', { dir: 'auto', text: s.branch_address || '' })]);
	if (s.branch_address_ja && s.branch_address_ja !== s.branch_address && lang !== 'ja') {
		addr.appendChild(el('br'));
		addr.appendChild(el('span', { lang: 'ja', dir: 'ltr', class: 'momo-small momo-muted', text: s.branch_address_ja }));
	}
	var dl = el('dl', { class: 'momo-res-dl' }, [
		row(t('res.label.branch'), el('span', {}, [el('strong', { text: s.branch_name || '' }), el('br'), addr])),
		row(t('res.label.date'), d.createTextNode(R.fmtDate(s.date, 'long'))),
		row(t('res.label.time'), R.timeNode(s.time)),
		row(t('res.label.party'), R.bdi(String(s.party_size))),
		row(t('res.label.name'), el('bdi', { text: s.guest_name || '' }))
	]);
	var emailTo = R.fill(el('p'), 'res.submitted.email_to', { masked_email: R.bdi(data.email_masked || '') });
	out.appendChild(el('section', { class: 'momo-card momo-stack', 'aria-labelledby': 'res-sum-h' }, [el('h2', { class: 'momo-h3', id: 'res-sum-h', text: t('res.summary.title') }), dl, emailTo]));

	// 4. Next steps.
	var next = el('section', { class: 'momo-stack', 'aria-labelledby': 'res-next-h' }, [el('h2', { class: 'momo-h3', id: 'res-next-h', text: t('res.next.title') })]);
	var steps = el('ul', { class: 'momo-res-next' });
	steps.appendChild(el('li', { text: t('res.submitted.next') }));
	var dl2 = R.jstParts(data.decision_deadline);
	if (dl2) {
		var when = el('span', {}, [dl2.date === s.date ? '' : R.fmtDate(dl2.date, 'short') + ' ', R.timeNode(dl2.time)]);
		steps.appendChild(R.fill(el('li'), 'res.submitted.expiry', { time: when }));
	}
	if (data.response_promise_minutes) {
		steps.appendChild(el('li', { text: t('res.submitted.promise', { duration: R.duration(data.response_promise_minutes) }) }));
	}
	steps.appendChild(el('li', { class: 'momo-strong', text: t('res.submitted.dont_travel') }));
	next.appendChild(steps);
	out.appendChild(next);

	// 5–6. Status link, branch phone.
	var actions = el('div', { class: 'momo-cluster' });
	if (data.status_url) {
		actions.appendChild(el('a', { class: 'momo-btn momo-btn--primary', href: data.status_url, rel: 'noreferrer' }, [el('span', { text: t('res.submitted.status_link') })]));
	}
	var c = data.branch_contact || {};
	if (c.phone_e164 || c.phone_display) {
		var tel = R.telLink(c.phone_e164, c.phone_display, 'momo-btn momo-btn--secondary');
		tel.insertBefore(el('span', { class: 'momo-visually-hidden', text: t('cta.call') + ' ' + (s.branch_name || '') + ' ' }), tel.lastChild);
		actions.appendChild(tel);
	}
	if (actions.children.length) { out.appendChild(actions); }

	// 7. Carrier mail tip (FR-RES-029).
	if (data.carrier_email && data.email_delivery_tip) {
		out.appendChild(el('div', { class: 'momo-alert momo-alert--info' }, [R.icon('info'), el('p', { text: data.email_delivery_tip })]));
	}

	root.querySelector('[data-res-generic]').hidden = true;
	out.hidden = false;
	heading.focus();
}(window, document));
