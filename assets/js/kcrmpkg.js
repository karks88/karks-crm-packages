( function () {
	// wp-admin package list: clicking anywhere on a package row opens it, unless
	// the click was on a link (Edit/Delete keep their own behavior) or the user
	// is selecting text. Ctrl/Cmd/middle-click opens it in a new tab, like a
	// normal link.
	function openRow( event, row ) {
		if ( event.target.closest( 'a, button, input, select, textarea' ) ) {
			return;
		}
		if ( window.getSelection && String( window.getSelection() ).length ) {
			return;
		}
		if ( event.ctrlKey || event.metaKey || 1 === event.button ) {
			window.open( row.dataset.href, '_blank' );
		} else {
			window.location.href = row.dataset.href;
		}
	}
	document.querySelectorAll( '.kcrmpkg-list tr.kcrmpkg-row' ).forEach( function ( row ) {
		row.addEventListener( 'click', function ( event ) { openRow( event, row ); } );
		row.addEventListener( 'auxclick', function ( event ) {
			if ( 1 === event.button ) {
				openRow( event, row );
			}
		} );
	} );

	// Package forms (wp-admin and front end): term packages have no hours, so
	// hide/unrequire Allotted Hours and require Period End instead.
	// KCRM_Pkg_Package::data_from_form() enforces the same rules server-side.
	document.querySelectorAll( 'form[data-kcrmpkg-package-form]' ).forEach( function ( form ) {
		var type  = form.querySelector( '[name="package_type"]' );
		var hours = form.querySelector( '[name="allotted_hours"]' );
		var end   = form.querySelector( '[name="period_end"]' );
		if ( ! type || ! hours || ! end ) {
			return;
		}
		function sync() {
			var isTerm = 'term' === type.value;
			form.querySelectorAll( '.kcrmpkg-hours-row' ).forEach( function ( row ) { row.style.display = isTerm ? 'none' : ''; } );
			form.querySelectorAll( '.kcrmpkg-term-only' ).forEach( function ( el ) { el.style.display = isTerm ? '' : 'none'; } );
			hours.required = ! isTerm;
			end.required   = isTerm;
		}
		type.addEventListener( 'change', sync );
		sync();
	} );
} )();
