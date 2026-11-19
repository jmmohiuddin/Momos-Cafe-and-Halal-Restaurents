/*! momos gallery.js — SCR-C22 only (FR-CORP-012(c), AC-CORP-10-01; Doc 07 §5 C22, Doc 08 CMP-Gallery): filter
 * chips + keyboard-operable lightbox in a native <dialog>. Without JS every photo stays a link to the full-size
 * image. Enter/click opens; focus stays in the dialog (Tab cycles); labelled previous/next buttons, arrow keys
 * (mirrored in RTL) and swipe move; Esc or Close closes and focus returns to the photo link. Vanilla, deferred, loaded on the gallery page only. */
(function () {
	'use strict';
	var d = document;
	var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };
	var gl = d.querySelector('[data-momo-gallery]'), gf = d.querySelector('[data-momo-gallery-filter]');
	if (!gl) { return; }

	// Filter chips (client-side; empty chips are not rendered).
	if (gf) {
		gf.hidden = false;
		gf.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-filter]');
			if (!b) { return; }
			var k = b.getAttribute('data-filter');
			$$('button[data-filter]', gf).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			$$(':scope > li', gl).forEach(function (li) { li.hidden = !!k && (li.getAttribute('data-keys') || '').split(' ').indexOf(k) === -1; });
		});
	}

	// Lightbox
	var dlg = d.querySelector('[data-momo-lightbox]');
	if (!dlg || typeof dlg.showModal !== 'function') { return; } // no <dialog>: links open the full-size image
	var img = dlg.querySelector('[data-momo-lb-img]'), cap = dlg.querySelector('[data-momo-lb-cap]'), cnt = dlg.querySelector('[data-momo-lb-count]');
	var items = [], cur = 0, opener = null, x0 = null;
	var rtl = function () { return getComputedStyle(dlg).direction === 'rtl'; };
	function show(i) {
		cur = (i + items.length) % items.length;
		var a = items[cur], th = a.querySelector('img'), fc = a.parentNode.querySelector('figcaption');
		img.src = a.href;
		img.alt = th ? th.alt : '';
		cap.textContent = fc ? fc.textContent : '';
		cap.hidden = !fc;
		cnt.textContent = cnt.getAttribute('data-template').replace('{n}', cur + 1).replace('{total}', items.length);
	}
	gl.addEventListener('click', function (e) {
		var a = e.target.closest('[data-momo-lb]');
		if (!a) { return; }
		e.preventDefault();
		items = $$('[data-momo-lb]', gl).filter(function (x) { return !x.closest('li').hidden; });
		opener = a;
		show(items.indexOf(a));
		dlg.showModal();
		dlg.querySelector('[data-momo-lb-close]').focus();
	});
	dlg.addEventListener('close', function () { img.src = 'data:,'; if (opener) { opener.focus(); } });
	dlg.addEventListener('click', function (e) {
		var s = e.target.closest('[data-momo-lb-step]');
		if (s) { show(cur + parseInt(s.getAttribute('data-momo-lb-step'), 10)); return; }
		if (e.target === dlg || e.target.closest('[data-momo-lb-close]')) { dlg.close(); }
	});
	dlg.addEventListener('keydown', function (e) {
		if (e.key === 'Tab') { // focus trap: Tab cycles through the dialog's buttons (FR-CORP-012(c))
			var f = $$('button', dlg), t = e.shiftKey ? f[0] : f[f.length - 1];
			if (d.activeElement === t) { e.preventDefault(); f[e.shiftKey ? f.length - 1 : 0].focus(); }
			return;
		}
		var k = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0);
		if (k) { e.preventDefault(); show(cur + (rtl() ? -k : k)); }
	});
	// Swipe on touch (pointer events): a horizontal move of ≥ 40 px steps in reading direction.
	dlg.addEventListener('pointerdown', function (e) { x0 = e.clientX; });
	dlg.addEventListener('pointerup', function (e) {
		if (x0 === null) { return; }
		var dx = e.clientX - x0; x0 = null;
		if (Math.abs(dx) >= 40 && e.pointerType !== 'mouse') { show(cur + ((dx < 0) !== rtl() ? 1 : -1)); }
	});
}());
