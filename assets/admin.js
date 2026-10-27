/**
 * Content Powered > Easy Mailchimp Newsletter Embed page behaviour. Only loaded on that page.
 *
 * Copy buttons, delete and disconnect confirmations, rows that depend on
 * another control, the form editor's live preview and accent color, the
 * unsaved-changes warning and the Turnstile key test. emne_admin.ajaxUrl,
 * .nonce, .turnstileSrc and .i18n are passed in from PHP (see
 * Easy_Mailchimp_Newsletter_Embed_Admin::enqueue_assets).
 */

/* global emne_admin */
( function () {
	'use strict';

	var data = window.emne_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		initCopy();
		initConfirms();
		initDepends();
		initEditor( document.querySelector( '.emne-editor' ) );
		initTurnstileTest();
	} );

	// Copy buttons ------------------------------------------------------------

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.emne-copy' );
			if ( ! button ) {
				return;
			}

			var text = button.getAttribute( 'data-copy' );
			var label = button.querySelector( 'span' );
			var original = label ? label.textContent : '';

			function flash( message ) {
				if ( label ) {
					label.textContent = message;
				}
				button.classList.add( 'is-copied' );
				window.setTimeout( function () {
					if ( label ) {
						label.textContent = original;
					}
					button.classList.remove( 'is-copied' );
				}, 1600 );
			}

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						flash( data.i18n.copied );
					},
					function () {
						selectCode( button );
						flash( data.i18n.copyFailed );
					}
				);
			} else {
				selectCode( button );
				try {
					flash( document.execCommand( 'copy' ) ? data.i18n.copied : data.i18n.copyFailed );
				} catch ( e ) {
					flash( data.i18n.copyFailed );
				}
			}
		} );
	}

	function selectCode( button ) {
		var code = button.parentNode.querySelector( 'code' );
		if ( code && window.getSelection ) {
			var range = document.createRange();
			range.selectNodeContents( code );
			window.getSelection().removeAllRanges();
			window.getSelection().addRange( range );
		}
	}

	// Confirmations -------------------------------------------------------------

	function initConfirms() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.emne-delete' ) && ! window.confirm( data.i18n.confirmDelete ) ) {
				event.preventDefault();
			}
			if ( event.target.closest( '.emne-disconnect' ) && ! window.confirm( data.i18n.confirmDisc ) ) {
				event.preventDefault();
			}
		} );
	}

	// Rows that depend on another control ----------------------------------------

	function controlValue( key ) {
		var controls = document.querySelectorAll( '[name="emne[' + key + ']"], [name="' + key + '"]' );
		var value = null;

		controls.forEach( function ( control ) {
			if ( 'radio' === control.type ) {
				if ( control.checked ) {
					value = control.value;
				}
			} else if ( 'checkbox' === control.type ) {
				value = control.checked ? '' : null;
			} else {
				value = control.value;
			}
		} );

		return value;
	}

	function initDepends() {
		var items = document.querySelectorAll( '[data-emne-depends]' );

		if ( ! items.length ) {
			return;
		}

		function update() {
			items.forEach( function ( item ) {
				var value = controlValue( item.getAttribute( 'data-emne-depends' ) );
				var wanted = item.getAttribute( 'data-emne-value' );
				var match = '' === wanted ? '' === value : null !== value && wanted.split( ',' ).indexOf( value ) !== -1;

				if ( item.hasAttribute( 'data-emne-invert' ) ) {
					match = ! match;
				}
				item.hidden = ! match;
			} );
		}

		document.addEventListener( 'change', update );
		update();
	}

	// Form editor ----------------------------------------------------------------

	function accentVars( hex ) {
		var rgb = [ 1, 3, 5 ].map( function ( i ) {
			return parseInt( hex.substr( i, 2 ), 16 );
		} );
		var light = ( rgb[ 0 ] * 299 + rgb[ 1 ] * 587 + rgb[ 2 ] * 114 ) / 1000 >= 165;
		var soft = rgb.map( function ( c ) {
			return Math.round( c * 0.12 + 255 * 0.88 );
		} );

		return {
			'--emne-accent': hex,
			'--emne-on-accent': light ? '#111827' : '#ffffff',
			'--emne-soft': 'rgb(' + soft.join( ',' ) + ')',
			'--emne-bold-btn': light ? '#111827' : '#ffffff',
		};
	}

	function initEditor( form ) {
		if ( ! form ) {
			return;
		}

		var preview = document.querySelector( '.emne-preview' );
		var picker = document.getElementById( 'emne-accent-picker' );
		var hexInput = document.getElementById( 'emne-accent' );
		var themes = form.querySelector( '.emne-themes' );
		var timer = null;
		var request = 0;
		var dirty = false;

		function paintThemes() {
			if ( ! themes || ! /^#[0-9a-f]{6}$/i.test( hexInput.value ) ) {
				return;
			}
			var vars = accentVars( hexInput.value.toLowerCase() );
			Object.keys( vars ).forEach( function ( name ) {
				themes.style.setProperty( name, vars[ name ] );
			} );
		}

		if ( picker && hexInput ) {
			picker.addEventListener( 'input', function () {
				hexInput.value = picker.value;
				paintThemes();
				schedule();
			} );
			hexInput.addEventListener( 'input', function () {
				if ( /^#[0-9a-f]{6}$/i.test( hexInput.value ) ) {
					picker.value = hexInput.value.toLowerCase();
					paintThemes();
				}
			} );
		}

		function refresh() {
			var body = new FormData( form );
			var mine = ++request;

			body.set( 'action', 'emne_preview' );
			body.set( 'nonce', data.nonce );
			body.set( 'form_id', form.getAttribute( 'data-form-id' ) || '0' );
			preview.classList.add( 'is-loading' );

			window
				.fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( res ) {
					// Only the newest answer counts.
					if ( mine === request && res && res.success && res.data && 'string' === typeof res.data.html ) {
						preview.innerHTML = res.data.html;
					}
				} )
				.catch( function () {} )
				.then( function () {
					if ( mine === request ) {
						preview.classList.remove( 'is-loading' );
					}
				} );
		}

		function schedule() {
			if ( ! preview ) {
				return;
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( refresh, 300 );
		}

		form.addEventListener( 'input', function () {
			dirty = true;
			schedule();
		} );
		form.addEventListener( 'change', function () {
			dirty = true;
			schedule();
		} );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = data.i18n.unsaved;
				return data.i18n.unsaved;
			}
		} );

		// The delete and duplicate links leave the page on purpose.
		form.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.emne-save-links a' ) ) {
				dirty = false;
			}
		} );
	}

	// Turnstile key test --------------------------------------------------------

	function initTurnstileTest() {
		var button = document.getElementById( 'emne-ts-test' );
		var box = document.getElementById( 'emne-ts-test-widget' );
		var result = document.getElementById( 'emne-ts-test-result' );
		var widget = null;

		if ( ! button || ! box || ! result ) {
			return;
		}

		function say( text, kind ) {
			result.textContent = text;
			result.className = 'emne-test-result' + ( kind ? ' is-' + kind : '' );
		}

		function check( token ) {
			var body = new FormData();
			body.append( 'action', 'emne_test_turnstile' );
			body.append( 'nonce', data.nonce );
			body.append( 'token', token );
			body.append( 'secret', document.getElementById( 'emne-ts-secret' ).value.trim() );
			say( data.i18n.checking, '' );

			window
				.fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( res ) {
					say( res && res.data && res.data.message ? res.data.message : data.i18n.failed, res && res.success ? 'ok' : 'error' );
				} )
				.catch( function () {
					say( data.i18n.failed, 'error' );
				} )
				.then( function () {
					button.disabled = false;
				} );
		}

		function render() {
			if ( null !== widget ) {
				window.turnstile.remove( widget );
				widget = null;
			}
			box.innerHTML = '';
			say( data.i18n.solve, '' );

			try {
				widget = window.turnstile.render( box, {
					sitekey: document.getElementById( 'emne-ts-site' ).value.trim(),
					action: 'emne-subscribe',
					callback: check,
					'error-callback': function () {
						say( data.i18n.widgetError, 'error' );
						button.disabled = false;
						return true;
					},
				} );
			} catch ( e ) {
				say( data.i18n.widgetError, 'error' );
				button.disabled = false;
			}
		}

		button.addEventListener( 'click', function () {
			if ( ! document.getElementById( 'emne-ts-site' ).value.trim() ) {
				say( data.i18n.noSiteKey, 'error' );
				return;
			}

			button.disabled = true;

			if ( window.turnstile ) {
				render();
				return;
			}

			say( data.i18n.testing, '' );
			window.emneAdminTurnstile = render;

			var script = document.createElement( 'script' );
			script.src = data.turnstileSrc + '&onload=emneAdminTurnstile';
			script.async = true;
			script.onerror = function () {
				say( data.i18n.scriptError, 'error' );
				button.disabled = false;
				script.remove();
			};
			document.head.appendChild( script );
		} );
	}
} )();
