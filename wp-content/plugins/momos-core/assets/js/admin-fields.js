/* momos-core admin field behaviours: media pickers, repeaters, conditional fields. No dependencies beyond jQuery/wp.media. */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.momos-media__choose', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.momos-media' );
		var multiple = box.data( 'multiple' ) === 1 || box.data( 'multiple' ) === '1';
		var frame = wp.media( { multiple: multiple, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var ids = [];
			var html = '';
			frame.state().get( 'selection' ).each( function ( att ) {
				var a = att.toJSON();
				ids.push( a.id );
				var src = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
				html += '<img src="' + encodeURI( src ) + '" alt="" width="80" height="80">';
			} );
			box.find( '.momos-media__value' ).val( ids.join( ',' ) );
			box.find( '.momos-media__preview' ).html( html );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.momos-media__clear', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '.momos-media' );
		box.find( '.momos-media__value' ).val( '' );
		box.find( '.momos-media__preview' ).empty();
	} );

	$( document ).on( 'click', '.momos-repeater__add', function ( e ) {
		e.preventDefault();
		var rep = $( this ).closest( '.momos-repeater' );
		var body = rep.find( '.momos-repeater__table > tbody' );
		var max = parseInt( rep.data( 'max' ), 10 ) || 50;
		if ( body.children( 'tr' ).length >= max ) {
			return;
		}
		var idx = Date.now().toString( 36 );
		var tpl = rep.find( 'template.momos-repeater__template' ).html().replace( /__i__/g, idx );
		body.append( tpl );
	} );

	$( document ).on( 'click', '.momos-repeater__remove', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'tr' ).remove();
	} );

	function applyConditions() {
		$( '[data-show-if]' ).each( function () {
			var row = $( this );
			var rules = row.data( 'show-if' );
			var visible = true;
			$.each( rules, function ( field, expected ) {
				var input = $( '[name="momos_fields[' + field + ']"]' ).filter( function () {
					return this.type !== 'hidden' || $( '[name="momos_fields[' + field + ']"]' ).length === 1;
				} );
				var val = input.is( ':checkbox' ) ? ( input.is( ':checked' ) ? '1' : '0' ) : input.filter( ':checked' ).val() || input.val();
				var list = Array.isArray( expected ) ? expected.map( String ) : [ String( expected ) ];
				if ( list.indexOf( String( val ) ) === -1 ) {
					visible = false;
				}
			} );
			row.toggle( visible );
		} );
	}
	$( document ).on( 'change', '.momos-fields :input', applyConditions );
	$( applyConditions );
}( jQuery ) );
