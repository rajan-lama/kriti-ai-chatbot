/**
 * Knowledge Sources screen: queues work and runs it in small batches so the page can show progress.
 */
( function () {
	'use strict';

	var cfg = window.KAIAdmin;
	var sync = document.getElementById( 'kai-sync' );
	var reindex = document.getElementById( 'kai-reindex' );
	var retry = document.getElementById( 'kai-retry' );
	var status = document.getElementById( 'kai-sync-status' );
	var bar = document.getElementById( 'kai-sync-bar' );

	if ( ! cfg || ! sync || ! window.fetch ) {
		return;
	}

	var buttons = [ sync, reindex, retry ].filter( Boolean );

	function fmt( template, values ) {
		return template.replace( /%(\d)\$s|%s/g, function ( m, i ) {
			return values[ i ? i - 1 : 0 ];
		} );
	}

	function busy( on ) {
		buttons.forEach( function ( b ) {
			b.disabled = on;
		} );
	}

	function call( path ) {
		return window.fetch( cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( data && ( data.message || ( data.error && data.error.message ) ) || cfg.i18n.failed );
				}
				return data;
			} );
		} );
	}

	function show( state ) {
		bar.hidden = false;
		bar.max = Math.max( 1, state.total );
		bar.value = Math.min( state.done, state.total );
		status.textContent = fmt( cfg.i18n.progress, [ state.done, state.total ] );
	}

	function loop( state ) {
		show( state );

		if ( ! state.running ) {
			status.textContent = fmt( cfg.i18n.done, [ state.indexed, state.same, state.errors ] );
			busy( false );
			// Reload so the table and the status cards show the new numbers.
			window.setTimeout( function () {
				window.location.reload();
			}, 1200 );
			return;
		}

		call( '/process' ).then( loop ).catch( fail );
	}

	function fail( error ) {
		status.textContent = error.message || cfg.i18n.failed;
		busy( false );
	}

	function start( path ) {
		busy( true );
		status.textContent = cfg.i18n.working;
		call( path ).then( loop ).catch( fail );
	}

	sync.addEventListener( 'click', function () {
		start( '/sync' );
	} );

	if ( reindex ) {
		reindex.addEventListener( 'click', function () {
			start( '/reindex' );
		} );
	}

	if ( retry ) {
		retry.addEventListener( 'click', function () {
			busy( true );
			status.textContent = cfg.i18n.working;
			call( '/retry' )
				.then( function ( data ) {
					status.textContent = fmt( cfg.i18n.retried, [ data.retried ] );
					return call( '/process' ).then( loop );
				} )
				.catch( fail );
		} );
	}

	// Work that is still queued (started earlier, or by cron) is picked up.
	if ( sync.getAttribute( 'data-running' ) === '1' ) {
		busy( true );
		call( '/process' ).then( loop ).catch( fail );
	}
} )();
