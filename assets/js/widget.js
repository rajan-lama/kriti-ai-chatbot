/**
 * Kriti AI Chatbot widget. No dependencies.
 *
 * Answers are plain text with light markdown. Everything from the server is escaped before it is
 * turned into markup, and links are only made for http(s) addresses.
 */
( function () {
	'use strict';

	var cfg = window.KritiAIChatbot;
	var root = document.getElementById( 'kai-chatbot-root' );

	if ( ! cfg || ! root || ! window.fetch ) {
		return;
	}

	var t = cfg.i18n;
	var STORE_KEY = 'kai-chatbot-v2';
	var MAX_SAVED = 30;
	var MAX_AGE = 7 * 24 * 60 * 60 * 1000; // History is forgotten after seven days.
	var state = { open: false, busy: false, conversationId: '', messages: [], lastQuestion: '' };

	/* ---------- storage (this browser only, never required) ---------- */
	// The conversation id, the messages and whether the window is open are kept in localStorage,
	// so the chat is the same on every page, in new tabs and on the next visit.

	function storage() {
		try {
			return window.localStorage;
		} catch ( e ) {
			return null;
		}
	}

	function load() {
		var store = storage();
		if ( ! store ) {
			return;
		}
		try {
			var saved = JSON.parse( store.getItem( STORE_KEY ) || 'null' );
			if ( saved && Array.isArray( saved.messages ) && saved.time && Date.now() - saved.time < MAX_AGE ) {
				state.conversationId = typeof saved.conversationId === 'string' ? saved.conversationId : '';
				state.messages = saved.messages.slice( -MAX_SAVED );
				state.restoreOpen = !! saved.open;
			} else if ( saved ) {
				store.removeItem( STORE_KEY );
			}
		} catch ( e ) {}
	}

	function save() {
		var store = storage();
		if ( ! store ) {
			return;
		}
		try {
			if ( ! cfg.store ) {
				store.removeItem( STORE_KEY );
				return;
			}
			store.setItem( STORE_KEY, JSON.stringify( { time: Date.now(), open: state.open, conversationId: state.conversationId, messages: state.messages.slice( -MAX_SAVED ) } ) );
		} catch ( e ) {}
	}

	/* ---------- text to safe markup ---------- */

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function safeUrl( u ) {
		return /^(https?:\/\/|\/(?!\/))/i.test( u ) ? u : '';
	}

	function inline( s ) {
		var out = esc( s );
		out = out.replace( /`([^`]+)`/g, '<code>$1</code>' );
		out = out.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		out = out.replace( /\[([^\]]+)\]\(([^)\s]+)\)/g, function ( m, label, url ) {
			var u = safeUrl( url.replace( /&amp;/g, '&' ) );
			return u ? '<a href="' + esc( u ) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>' : label;
		} );
		out = out.replace( /(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;:!?])/g, function ( m, lead, url ) {
			return lead + '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
		} );
		return out;
	}

	function markdown( text ) {
		var lines = String( text ).replace( /\r/g, '' ).split( '\n' );
		var html = '';
		var list = '';
		var para = [];

		function flushPara() {
			if ( para.length ) {
				html += '<p>' + inline( para.join( ' ' ) ) + '</p>';
				para = [];
			}
		}
		function closeList() {
			if ( list ) {
				html += '</' + list + '>';
				list = '';
			}
		}

		lines.forEach( function ( line ) {
			var ul = line.match( /^\s*[-*]\s+(.*)$/ );
			var ol = line.match( /^\s*\d+[.)]\s+(.*)$/ );

			if ( ul || ol ) {
				flushPara();
				var kind = ul ? 'ul' : 'ol';
				if ( list !== kind ) {
					closeList();
					html += '<' + kind + '>';
					list = kind;
				}
				html += '<li>' + inline( ( ul || ol )[ 1 ] ) + '</li>';
			} else if ( /^\s*$/.test( line ) ) {
				flushPara();
				closeList();
			} else {
				closeList();
				para.push( line.trim() );
			}
		} );

		flushPara();
		closeList();
		return html;
	}

	/* ---------- DOM ---------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k === 'html' ) {
				node.innerHTML = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( c );
		} );
		return node;
	}

	var wrap = el( 'div', { class: 'kai-chat kai-chat--' + ( cfg.position === 'left' ? 'left' : 'right' ) } );
	wrap.style.setProperty( '--kai-chat-color', /^#[0-9a-f]{3,8}$/i.test( cfg.color ) ? cfg.color : '#2f4bd6' );

	var launcher = el( 'button', { type: 'button', class: 'kai-chat__launcher', 'aria-expanded': 'false', 'aria-controls': 'kai-chat-panel', 'aria-label': t.open } );
	launcher.innerHTML = '<svg aria-hidden="true" focusable="false" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>';

	var panel = el( 'div', { class: 'kai-chat__panel', id: 'kai-chat-panel', role: 'dialog', 'aria-label': cfg.title, hidden: 'hidden' } );

	var title = el( 'h2', { class: 'kai-chat__title', text: cfg.title } );
	var resetBtn = el( 'button', { type: 'button', class: 'kai-chat__icon', 'aria-label': t.reset, title: t.reset, text: '↺' } );
	var closeBtn = el( 'button', { type: 'button', class: 'kai-chat__icon', 'aria-label': t.close, title: t.close, text: '✕' } );
	var header = el( 'div', { class: 'kai-chat__header' }, [ title, resetBtn, closeBtn ] );

	var log = el( 'div', { class: 'kai-chat__log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' } );

	var input = el( 'textarea', { class: 'kai-chat__input', rows: '1', placeholder: cfg.placeholder, 'aria-label': t.label, maxlength: String( cfg.maxChars ) } );
	var send = el( 'button', { type: 'submit', class: 'kai-chat__send', text: t.send } );
	var form = el( 'form', { class: 'kai-chat__form' }, [ input, send ] );

	panel.appendChild( header );
	panel.appendChild( log );
	panel.appendChild( form );
	wrap.appendChild( panel );
	wrap.appendChild( launcher );
	root.appendChild( wrap );

	/* ---------- rendering messages ---------- */

	function rate( msg, rating, buttons, note ) {
		if ( msg.rated || ! msg.id || ! state.conversationId ) {
			return;
		}

		msg.rated = rating;
		save();
		buttons.forEach( function ( b ) {
			b.disabled = true;
		} );
		note.textContent = t.thanks;

		window.fetch( cfg.feedback, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { conversation_id: state.conversationId, message_id: msg.id, rating: rating } ),
		} ).catch( function () {} );
	}

	function bubble( msg ) {
		var who = msg.role === 'user' ? t.you : t.bot;
		var body = el( 'div', { class: 'kai-chat__body' } );

		body.innerHTML = msg.role === 'user' ? '<p>' + esc( msg.text ) + '</p>' : markdown( msg.text );

		var node = el( 'div', { class: 'kai-chat__msg kai-chat__msg--' + ( msg.role === 'user' ? 'user' : 'bot' ) + ( msg.error ? ' is-error' : '' ) }, [
			el( 'span', { class: 'kai-chat__who', text: who } ),
			body,
		] );

		if ( msg.sources && msg.sources.length && cfg.sources ) {
			var items = msg.sources.map( function ( s, i ) {
				var u = safeUrl( s.url || '' );
				var label = el( u ? 'a' : 'span', u ? { href: u, target: '_blank', rel: 'noopener noreferrer' } : {} );
				label.textContent = ( i + 1 ) + '. ' + s.title;
				return el( 'li', {}, [ label ] );
			} );
			node.appendChild( el( 'div', { class: 'kai-chat__sources' }, [ el( 'strong', { text: t.sources } ), el( 'ul', {}, items ) ] ) );
		}

		// Rating is offered for answers the server stored (it gave an id), not for "I don't know" replies without one.
		if ( msg.id && ! msg.error ) {
			var up = el( 'button', { type: 'button', class: 'kai-chat__rate', 'aria-label': t.helpful, title: t.helpful, text: '👍' } );
			var down = el( 'button', { type: 'button', class: 'kai-chat__rate', 'aria-label': t.notHelpful, title: t.notHelpful, text: '👎' } );
			var note = el( 'span', { class: 'kai-chat__thanks', role: 'status', text: msg.rated ? t.thanks : '' } );
			var buttons = [ up, down ];

			if ( msg.rated ) {
				buttons.forEach( function ( b ) {
					b.disabled = true;
				} );
				( msg.rated === 'helpful' ? up : down ).classList.add( 'is-chosen' );
			}

			up.addEventListener( 'click', function () {
				up.classList.add( 'is-chosen' );
				rate( msg, 'helpful', buttons, note );
			} );
			down.addEventListener( 'click', function () {
				down.classList.add( 'is-chosen' );
				rate( msg, 'not_helpful', buttons, note );
			} );
			node.appendChild( el( 'div', { class: 'kai-chat__rating' }, [ up, down, note ] ) );
		}

		if ( msg.error ) {
			var retry = el( 'button', { type: 'button', class: 'kai-chat__retry', text: t.retry } );
			retry.addEventListener( 'click', function () {
				state.messages = state.messages.filter( function ( m ) {
					return m !== msg;
				} );
				ask( state.lastQuestion, true );
			} );
			node.appendChild( retry );
		}

		return node;
	}

	function render() {
		log.textContent = '';
		log.appendChild( bubble( { role: 'bot', text: cfg.greeting } ) );

		if ( ! state.messages.length && cfg.prompts && cfg.prompts.length ) {
			var chips = el( 'div', { class: 'kai-chat__prompts', role: 'group', 'aria-label': t.suggested } );
			cfg.prompts.forEach( function ( prompt ) {
				var chip = el( 'button', { type: 'button', class: 'kai-chat__prompt', text: prompt } );
				chip.addEventListener( 'click', function () {
					ask( prompt, false );
				} );
				chips.appendChild( chip );
			} );
			log.appendChild( chips );
		}

		state.messages.forEach( function ( m ) {
			log.appendChild( bubble( m ) );
		} );
		if ( state.busy ) {
			log.appendChild( el( 'div', { class: 'kai-chat__msg kai-chat__msg--bot kai-chat__typing', text: t.thinking } ) );
		}
		log.scrollTop = log.scrollHeight;
	}

	/* ---------- talking to the server ---------- */

	function ask( question, isRetry ) {
		question = String( question ).trim();
		if ( ! question || state.busy ) {
			return;
		}

		state.lastQuestion = question;
		if ( ! isRetry ) {
			state.messages.push( { role: 'user', text: question } );
		}
		state.busy = true;
		send.disabled = true;
		render();

		var ctx = { page_url: window.location.href.split( '#' )[ 0 ], post_id: cfg.context.post_id, post_type: cfg.context.post_type };
		var ctl = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = ctl ? window.setTimeout( function () { ctl.abort(); }, 70000 ) : 0;

		window.fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( { message: question, conversation_id: state.conversationId, context: ctx } ),
			signal: ctl ? ctl.signal : undefined,
		} )
			.then( function ( res ) {
				return res.json().catch( function () { return {}; } ).then( function ( data ) {
					return { ok: res.ok, status: res.status, data: data };
				} );
			} )
			.then( function ( r ) {
				if ( r.ok && r.data && r.data.message && r.data.message.content ) {
					state.conversationId = r.data.conversation_id || state.conversationId;
					state.messages.push( { role: 'bot', text: r.data.message.content, sources: r.data.message.citations || [], id: r.data.message.id || 0 } );
				} else {
					var message = r.data && r.data.error && r.data.error.message ? r.data.error.message : t.error;
					var id = r.data && r.data.error && r.data.error.request_id ? ' (' + r.data.error.request_id + ')' : '';
					state.messages.push( { role: 'bot', text: message + ( r.status >= 500 ? id : '' ), error: r.status !== 400 } );
				}
			} )
			.catch( function () {
				state.messages.push( { role: 'bot', text: t.error, error: true } );
			} )
			.then( function () {
				window.clearTimeout( timer );
				state.busy = false;
				send.disabled = false;
				save();
				render();
				if ( state.open ) {
					input.focus();
				}
			} );
	}

	/* ---------- open, close, reset ---------- */

	function setOpen( open, quiet ) {
		state.open = open;
		save();
		panel.hidden = ! open;
		launcher.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		launcher.setAttribute( 'aria-label', open ? t.close : t.open );
		if ( open ) {
			render();
			if ( ! quiet ) {
				input.focus();
			}
		} else if ( ! quiet ) {
			launcher.focus();
		}
	}

	launcher.addEventListener( 'click', function () {
		setOpen( ! state.open );
	} );
	closeBtn.addEventListener( 'click', function () {
		setOpen( false );
	} );
	resetBtn.addEventListener( 'click', function () {
		state.conversationId = '';
		state.messages = [];
		save();
		render();
		input.focus();
	} );
	panel.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			setOpen( false );
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var q = input.value;
		input.value = '';
		input.style.height = '';
		ask( q, false );
	} );

	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
			e.preventDefault();
			form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		}
	} );
	input.addEventListener( 'input', function () {
		input.style.height = 'auto';
		input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
	} );

	// The shortcode and the block render a button with this class.
	document.addEventListener( 'click', function ( e ) {
		var target = e.target && e.target.closest ? e.target.closest( '.kai-chat-open' ) : null;
		if ( target ) {
			e.preventDefault();
			setOpen( true );
		}
	} );

	load();
	if ( state.restoreOpen ) {
		setOpen( true, true );
	}
} )();
