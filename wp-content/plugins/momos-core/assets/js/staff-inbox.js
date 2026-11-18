/**
 * Staff inbox enhancements (SCR-A08/A09, FR-RES-028). The screens work without this script.
 *  - Inbox: polls API-10 every 60 s with `since`; shows "{n} new — Show" (role=status) without moving
 *    the visible rows; pauses while offline and shows a banner.
 *  - Detail: confirm before taking over a claimed request; character counters on limited textareas.
 *  - Menu badge: pending count refreshed through the WordPress Heartbeat.
 */
( function () {
	'use strict';
	var cfg = window.momosInbox || {};
	var i18n = cfg.i18n || {};

	function poll() {
		var inbox = document.getElementById( 'momos-inbox' );
		if ( ! inbox || ! cfg.api || ! window.fetch ) {
			return;
		}
		var since = cfg.since;
		var bar = document.getElementById( 'momos-newbar' );
		var link = document.getElementById( 'momos-newbar-link' );
		var offline = document.getElementById( 'momos-offline' );
		var seen = 0;
		function tick() {
			if ( ! navigator.onLine ) {
				return;
			}
			var url = cfg.api + ( cfg.api.indexOf( '?' ) === -1 ? '?' : '&' ) + 'status=pending&per_page=1&since=' + encodeURIComponent( since );
			window.fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
				.then( function ( r ) {
					if ( ! r.ok ) {
						return;
					}
					var n = parseInt( r.headers.get( 'X-Momo-New-Count' ) || '0', 10 );
					if ( n > seen ) {
						seen = n;
						link.textContent = ( i18n['new'] || '%d new' ).replace( '%d', String( n ) );
						bar.hidden = false;
					}
				} )
				.catch( function () {} );
		}
		function onlineState() {
			offline.hidden = navigator.onLine;
			offline.querySelector( 'p' ).textContent = i18n.offline || '';
			if ( navigator.onLine ) {
				tick();
			}
		}
		window.addEventListener( 'online', onlineState );
		window.addEventListener( 'offline', onlineState );
		window.setInterval( tick, cfg.interval || 60000 );
	}

	function takeover() {
		document.querySelectorAll( '[data-momos-takeover]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				var msg = ( i18n.takeover || 'Claimed by %s. Take over?' ).replace( '%s', btn.getAttribute( 'data-momos-takeover' ) );
				if ( ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );
		} );
	}

	function counters() {
		document.querySelectorAll( 'textarea[data-momos-counter]' ).forEach( function ( ta ) {
			var max = parseInt( ta.getAttribute( 'data-momos-counter' ), 10 );
			var out = document.createElement( 'span' );
			out.className = 'momos-muted';
			out.setAttribute( 'aria-live', 'polite' );
			ta.insertAdjacentElement( 'afterend', out );
			function update() {
				out.textContent = ta.value.length + '/' + max;
			}
			ta.addEventListener( 'input', update );
			update();
		} );
		// Disable submit buttons while a form is being sent (no double actions).
		document.querySelectorAll( '.momos-res form' ).forEach( function ( f ) {
			f.addEventListener( 'submit', function () {
				f.querySelectorAll( 'button[type=submit]' ).forEach( function ( b ) {
					window.setTimeout( function () {
						b.disabled = true;
					}, 0 );
				} );
			} );
		} );
	}

	function heartbeat() {
		if ( ! window.jQuery ) {
			return;
		}
		window.jQuery( document ).on( 'heartbeat-send', function ( e, data ) {
			data.momos_res_badge = 1;
		} ).on( 'heartbeat-tick', function ( e, data ) {
			var el = document.getElementById( 'momos-res-badge' );
			if ( el && data.momos_res_badge ) {
				el.textContent = String( data.momos_res_badge.pending );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		poll();
		takeover();
		counters();
		heartbeat();
	} );
}() );
