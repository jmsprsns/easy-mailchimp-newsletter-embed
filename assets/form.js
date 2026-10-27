/**
 * Easy Mailchimp Newsletter Embed: signup forms and popups.
 *
 * Each form carries its settings in a data-emne attribute and each popup in
 * data-emne-popup (see Easy_Mailchimp_Newsletter_Embed_Render), so there's no
 * inline script for an optimizer to delay or reorder. Cloudflare Turnstile,
 * when it's on, is loaded the first time someone clicks into a form.
 *
 * Signups fire a bubbling "emne:subscribed" event on the form's wrapper
 * (detail: {formId}) and, when Google Tag Manager is on the page, push
 * {event: "emne_subscribe", emneFormId} to the dataLayer.
 */
( function () {
	'use strict';

	var STORE = 'emne_';
	var MIN_MS = 1600;
	var pageStart = now();
	var ts = { loading: false, queue: [] };

	function now() {
		return window.performance && performance.now ? performance.now() : Date.now();
	}

	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return window.localStorage.getItem( STORE + key );
			}
			window.localStorage.setItem( STORE + key, value );
		} catch ( e ) {}
		return null;
	}

	function parse( el, attr ) {
		try {
			return JSON.parse( el.getAttribute( attr ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	// Turnstile -------------------------------------------------------------

	// Calls done( true ) once window.turnstile is there, or done( false ) if
	// the script can't load (blocked by an extension or the network).
	function loadTurnstile( src, done ) {
		if ( window.turnstile ) {
			done( true );
			return;
		}

		ts.queue.push( done );
		if ( ts.loading ) {
			return;
		}
		ts.loading = true;

		function flush( ok ) {
			var queue = ts.queue;
			ts.queue = [];
			queue.forEach( function ( fn ) {
				fn( ok );
			} );
		}

		window.emneTurnstileReady = function () {
			flush( true );
		};

		var script = document.createElement( 'script' );
		script.src = src + '&onload=emneTurnstileReady';
		script.async = true;
		script.onerror = function () {
			ts.loading = false;
			script.remove();
			flush( false );
		};
		document.head.appendChild( script );
	}

	// Forms -----------------------------------------------------------------

	function initForm( wrap ) {
		if ( wrap.emneReady ) {
			return;
		}
		wrap.emneReady = true;

		var cfg = parse( wrap, 'data-emne' );
		var msg = cfg.msg || {};
		var form = wrap.querySelector( 'form' );
		var message = wrap.querySelector( '.emne-message' );
		var button = wrap.querySelector( '.emne-button' );
		var tsBox = wrap.querySelector( '.emne-ts' );
		var shownAt = pageStart;
		var busy = false;
		var widget = null;
		var token = '';
		var waiting = null;

		if ( ! form || ! button || ! cfg.url ) {
			return;
		}

		var label = button.textContent;

		// Popups restart the clock when they open.
		wrap.emneShown = function () {
			shownAt = now();
		};

		function say( text, kind ) {
			message.textContent = text || '';
			message.className = 'emne-message' + ( text ? ' is-' + kind : '' );
		}

		function field( name ) {
			return form.querySelector( '[name="' + name + '"]' );
		}

		function invalid( input, text ) {
			say( text, 'error' );
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.focus();
			}
			return false;
		}

		function check() {
			var email = field( 'email' );
			var fname = field( 'fname' );
			var lname = field( 'lname' );
			var consent = field( 'consent' );

			form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
				el.removeAttribute( 'aria-invalid' );
			} );

			if ( fname && fname.required && ! fname.value.trim() ) {
				return invalid( fname, msg.name );
			}
			if ( lname && lname.required && ! lname.value.trim() ) {
				return invalid( lname, msg.name );
			}
			if ( ! email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email.value.trim() ) ) {
				return invalid( email, msg.email );
			}
			if ( consent && ! consent.checked ) {
				return invalid( consent, msg.consent );
			}
			return true;
		}

		function startTurnstile() {
			if ( ! cfg.ts || widget !== null || ! tsBox ) {
				return;
			}
			widget = false;
			loadTurnstile( cfg.ts.src, function ( ok ) {
				if ( ! ok ) {
					// Let a later click try again, and don't leave a submit hanging.
					widget = null;
					if ( waiting ) {
						waiting( false );
					}
					return;
				}
				widget = window.turnstile.render( tsBox, {
					sitekey: cfg.ts.key,
					action: cfg.ts.action,
					theme: cfg.ts.theme,
					appearance: cfg.ts.look,
					callback: function ( value ) {
						token = value;
						if ( waiting ) {
							waiting( true );
						}
					},
					'expired-callback': function () {
						token = '';
					},
					'error-callback': function () {
						token = '';
					},
				} );
			} );
		}

		function finish() {
			busy = false;
			waiting = null;
			button.disabled = false;
			button.textContent = label;
			form.removeAttribute( 'aria-busy' );
		}

		function send() {
			var data = new FormData( form );
			var elapsed = Math.round( now() - shownAt );

			waiting = null;
			data.append( 'action', 'emne_subscribe' );
			data.append( 'form_id', String( cfg.id ) );
			data.append( 'emne_t', String( elapsed ) );
			if ( token ) {
				data.set( 'cf-turnstile-response', token );
			}

			window
				.fetch( cfg.url, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				} )
				.then( function ( response ) {
					return response.json().catch( function () {
						return {};
					} );
				} )
				.then( function ( res ) {
					if ( res && res.ok ) {
						done( res );
					} else {
						say( ( res && res.message ) || msg.error, 'error' );
					}
				} )
				.catch( function () {
					say( msg.error, 'error' );
				} )
				.then( function () {
					finish();
					// A token works once; get a fresh one for another try.
					token = '';
					if ( widget && window.turnstile ) {
						window.turnstile.reset( widget );
					}
				} );
		}

		function done( res ) {
			wrap.classList.add( 'is-done' );
			say( res.message, 'success' );
			store( 'subscribed', '1' );

			try {
				wrap.dispatchEvent( new CustomEvent( 'emne:subscribed', { bubbles: true, detail: { formId: cfg.id } } ) );
			} catch ( e ) {}
			if ( window.dataLayer && window.dataLayer.push ) {
				window.dataLayer.push( { event: 'emne_subscribe', emneFormId: cfg.id } );
			}

			if ( res.redirect && /^https?:\/\//i.test( res.redirect ) ) {
				window.setTimeout( function () {
					window.location.href = res.redirect;
				}, 800 );
			}
		}

		form.addEventListener( 'focusin', startTurnstile );

		form.addEventListener( 'input', function ( event ) {
			if ( event.target.removeAttribute ) {
				event.target.removeAttribute( 'aria-invalid' );
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( busy || ! check() ) {
				return;
			}

			busy = true;
			button.disabled = true;
			button.textContent = msg.sending || label;
			form.setAttribute( 'aria-busy', 'true' );
			say( '', '' );

			// Real people never beat the server's minimum, so wait it out
			// instead of showing them an error.
			var wait = Math.max( 0, MIN_MS - ( now() - shownAt ) );

			window.setTimeout( function () {
				if ( ! cfg.ts || token ) {
					send();
					return;
				}

				var gaveUp = window.setTimeout( function () {
					waiting( false );
				}, 20000 );
				waiting = function ( ok ) {
					window.clearTimeout( gaveUp );
					if ( ok ) {
						send();
					} else {
						finish();
						say( msg.check, 'error' );
					}
				};
				startTurnstile();
			}, wait );
		} );
	}

	// Popups ----------------------------------------------------------------

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]):not([tabindex="-1"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])';

	function visible( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	function initPopup( popup ) {
		if ( popup.emneReady ) {
			return;
		}
		popup.emneReady = true;

		var cfg = parse( popup, 'data-emne-popup' );
		var dialog = popup.querySelector( '.emne-popup-dialog' );
		var wrap = popup.querySelector( '.emne' );
		var id = String( cfg.id );
		var shown = false;
		var lastFocus = null;

		function suppressed() {
			return '1' === store( 'subscribed' ) || parseInt( store( 'hide_' + id ) || '0', 10 ) > Date.now();
		}

		function deviceMatches() {
			var mobile = window.matchMedia && window.matchMedia( '(max-width: 767px)' ).matches;
			return 'all' === cfg.devices || ( 'mobile' === cfg.devices ) === mobile;
		}

		function focusables() {
			return Array.prototype.filter.call( dialog.querySelectorAll( FOCUSABLE ), function ( el ) {
				return visible( el ) && ! el.closest( '.emne-hp' );
			} );
		}

		function onKey( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				close();
				return;
			}
			if ( 'Tab' !== event.key || ! cfg.modal ) {
				return;
			}
			var items = focusables();
			if ( ! items.length ) {
				event.preventDefault();
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( event.shiftKey && ( document.activeElement === first || ! dialog.contains( document.activeElement ) ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		function open( manual ) {
			if ( popup.classList.contains( 'is-open' ) || ( ! manual && ( shown || suppressed() || ! deviceMatches() ) ) ) {
				return;
			}
			shown = true;
			lastFocus = document.activeElement;
			popup.hidden = false;
			void popup.offsetWidth;
			popup.classList.add( 'is-open' );

			if ( cfg.modal ) {
				document.documentElement.classList.add( 'emne-locked' );
			}
			if ( wrap && wrap.emneShown ) {
				wrap.emneShown();
			}

			// A corner box doesn't take focus unless someone asked for it.
			if ( cfg.modal || manual ) {
				var input = dialog.querySelector( '.emne-input' );
				( input && wrap && ! wrap.classList.contains( 'is-done' ) ? input : dialog ).focus();
			}
			document.addEventListener( 'keydown', onKey );
		}

		function close() {
			if ( ! popup.classList.contains( 'is-open' ) ) {
				return;
			}
			popup.classList.remove( 'is-open' );
			popup.hidden = true;
			document.documentElement.classList.remove( 'emne-locked' );
			document.removeEventListener( 'keydown', onKey );
			store( 'hide_' + id, String( Date.now() + ( cfg.days || 0 ) * 86400000 ) );

			if ( lastFocus && lastFocus.focus && document.contains( lastFocus ) ) {
				lastFocus.focus();
			}
		}

		popup.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '[data-emne-close]' ) ) {
				close();
			}
		} );

		// Links to #emne-popup-ID and anything with the class emne-open-ID open it.
		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest && event.target.closest( 'a[href$="#emne-popup-' + id + '"], .emne-open-' + id );
			if ( trigger && ! popup.contains( trigger ) ) {
				event.preventDefault();
				open( true );
			}
		} );

		if ( '#emne-popup-' + id === window.location.hash ) {
			open( true );
			return;
		}

		if ( suppressed() || ! deviceMatches() ) {
			return;
		}

		if ( 'delay' === cfg.trigger ) {
			window.setTimeout( function () {
				open( false );
			}, Math.max( 0, cfg.delay || 0 ) * 1000 );
		} else if ( 'scroll' === cfg.trigger ) {
			var onScroll = function () {
				var root = document.documentElement;
				var room = Math.max( 1, root.scrollHeight - window.innerHeight );
				if ( ( window.pageYOffset / room ) * 100 >= ( cfg.scroll || 50 ) ) {
					window.removeEventListener( 'scroll', onScroll );
					open( false );
				}
			};
			window.addEventListener( 'scroll', onScroll, { passive: true } );
		} else if ( 'exit' === cfg.trigger && window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches ) {
			// Desktop only: the pointer leaving through the top of the window.
			var onLeave = function ( event ) {
				if ( ! event.relatedTarget && event.clientY <= 0 ) {
					document.removeEventListener( 'mouseout', onLeave );
					open( false );
				}
			};
			document.addEventListener( 'mouseout', onLeave );
		}
	}

	// Start -----------------------------------------------------------------

	function init() {
		if ( ! window.fetch || ! window.FormData ) {
			return;
		}
		document.querySelectorAll( '.emne[data-emne]' ).forEach( initForm );
		document.querySelectorAll( '.emne-popup[data-emne-popup]' ).forEach( initPopup );
	}

	window.emneInit = init;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
