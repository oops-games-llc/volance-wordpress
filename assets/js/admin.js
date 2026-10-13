/**
 * Volance Detection admin: confirm before deleting a log entry.
 */
( function () {
	'use strict';
	var cfg = window.volanceDetectionAdmin || {};
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( target && target.closest && target.closest( '.volance-confirm-delete' ) ) {
			if ( ! window.confirm( cfg.confirmDelete || 'Delete this entry?' ) ) {
				event.preventDefault();
			}
		}
	} );
} )();
