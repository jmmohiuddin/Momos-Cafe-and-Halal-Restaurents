/*! momos datalayer.js — first-party analytics loader + dataLayer allow-list (i18n-seo-analytics.md §12; FR-ANL-001/002, DEC-030/066, PRV-022).
 * Only the documented events/params are pushed (no PII, no free text). Opt-out cookie or GPC → GTM never injected. */
(function (w, d) {
	'use strict';
	var dl = w.dataLayer = w.dataLayer || [];
	var me = d.querySelector('script[data-momo-datalayer]');
	var gtm = me ? me.getAttribute('data-gtm') : null;
	var L = /^(ja|en|ar|ur|hi|bn)$/, PT = /^(home|about|halal|branch_directory|branch|menu_hub|menu_category|dish|offers|reservation|contact|faq|news|legal|gallery|error_404|page)$/;
	var RX = {
		language: L, from_language: L, to_language: L, page_type: PT, source_page: /^(halal|faq)$/,
		branch_code: /^[A-Z]{3}$/, menu_category: /^(hub|[a-z0-9-]{1,60})$/, dish_sku: /^D\d{4}$/,
		cta_location: /^(header|hero|branch_card|branch_page|sticky_bar|menu_page|dish_page|offer_card|home_section|halal_page|footer|reservation_page|error_page)$/,
		party_size_band: /^(1-2|3-6|7\+)$/, lead_source: /^reservation_form$/
	};
	var EV = {
		momo_page: ['page_type', 'language', 'branch_code', 'menu_category', 'dish_sku'],
		view_branch: ['branch_code', 'language'], view_menu: ['menu_category', 'branch_code', 'language'],
		view_dish: ['dish_sku', 'menu_category', 'language'], view_halal_information: ['language', 'source_page'],
		select_language: ['from_language', 'to_language'], click_reservation: ['cta_location', 'branch_code', 'language'],
		reservation_form_start: ['branch_code', 'language'], generate_lead: ['branch_code', 'language', 'party_size_band', 'lead_source'],
		click_phone: ['branch_code', 'cta_location', 'language'], click_directions: ['branch_code', 'cta_location', 'language']
	};
	var lang = (d.documentElement.getAttribute('lang') || '').toLowerCase();
	function gtag() { dl.push(arguments); }
	w.gtag = w.gtag || gtag;
	function optedOut() { return /(?:^|;\s*)momo_analytics_optout=1/.test(d.cookie) || navigator.globalPrivacyControl === true; }
	var loaded = false;
	function inject() {
		if (loaded || !gtm || optedOut()) { return; }
		loaded = true;
		dl.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
		var s = d.createElement('script');
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtm);
		d.head.appendChild(s);
	}
	/** Push an allow-listed event; unknown keys and values failing their pattern are dropped. */
	function momoPush(event, params, cb) {
		var keys = EV[event], o = { event: event }, done = false;
		var fin = function () { if (!done && typeof cb === 'function') { done = true; cb(); } };
		if (!keys) { fin(); return false; }
		params = params || {};
		for (var i = 0; i < keys.length; i++) {
			var k = keys[i], v = k === 'language' && !params[k] ? lang : params[k];
			if (v === null && event === 'momo_page') { o[k] = null; } else if (typeof v === 'string' && RX[k].test(v)) { o[k] = v; }
		}
		if (cb) {
			o.eventCallback = fin;
			o.eventTimeout = 500;
			if (!loaded) { w.setTimeout(fin, 0); } else { w.setTimeout(fin, 500); }
		}
		dl.push(o);
		return true;
	}
	w.momoPush = momoPush;

	gtag('consent', 'default', { analytics_storage: optedOut() ? 'denied' : 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', functionality_storage: 'granted', security_storage: 'granted' });
	dl.push({ 'gtm.blocklist': ['customScripts', 'nonGoogleScripts', 'nonGooglePixels', 'nonGoogleIframes'] });

	var pt = '', data = d.getElementById('momo-page-data');
	if (data) {
		try {
			var j = JSON.parse(data.textContent || '{}');
			if (j.page) { pt = j.page.page_type || ''; momoPush('momo_page', j.page); }
			if (j.view && j.view.event) { momoPush(j.view.event, j.view); }
		} catch (e) { /* malformed page data: push nothing */ }
	}
	inject();

	function attr(el, n) { var x = el && el.closest('[' + n + ']'); return x ? x.getAttribute(n) : null; }
	d.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target : null;
		var el = t ? t.closest('[data-momo-event]') : null;
		var tel = t ? t.closest('a[href^="tel:"]') : null;
		if (el) {
			var ev = el.getAttribute('data-momo-event');
			if (ev === 'select_language') {
				momoPush(ev, { from_language: el.getAttribute('data-momo-from'), to_language: el.getAttribute('data-momo-to') });
			} else if (/^(click_reservation|click_phone|click_directions)$/.test(ev)) {
				momoPush(ev, { cta_location: attr(el, 'data-momo-cta'), branch_code: attr(el, 'data-momo-branch') });
			}
		} else if (tel) {
			momoPush('click_phone', { cta_location: attr(tel, 'data-momo-cta'), branch_code: attr(tel, 'data-momo-branch') });
		}
	}, true);
	d.addEventListener('momo:halal_faq_expand', function (e) {
		var sp = pt === 'halal' || pt === 'faq' ? pt : (e.detail && e.detail.source_page);
		momoPush('view_halal_information', { source_page: sp });
	});
	/* Form scripts may dispatch momo:analytics with {event, params} instead of calling momoPush. */
	d.addEventListener('momo:analytics', function (e) {
		if (e.detail && typeof e.detail.event === 'string') { momoPush(e.detail.event, e.detail.params, e.detail.callback); }
	});
	d.addEventListener('momo:analytics_optin', function () {
		if (!optedOut()) { gtag('consent', 'update', { analytics_storage: 'granted' }); inject(); }
	});
}(window, document));
