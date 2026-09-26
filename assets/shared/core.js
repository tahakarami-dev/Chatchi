/**
 * ChatChi shared front-end core.
 *
 * Used by both the visitor widget and the agent panel: safe DOM building,
 * icons, link detection, Persian-aware date and number formatting, and the
 * REST client. Exposed as window.ChatChiCore.
 *
 * Security rule: user-provided text is only ever inserted with textContent
 * or text nodes. innerHTML is used only for the constant SVG icons below.
 *
 * Icons: Lucide (https://lucide.dev), ISC License.
 */
( function () {
	'use strict';

	if ( window.ChatChiCore ) {
		return;
	}

	var ICONS = {
		chat: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
		close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		send: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
		check: '<path d="M20 6 9 17l-5-5"/>',
		checks: '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
		clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
		alert: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
		inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
		search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		back: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
		exit: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
		user: '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
		mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
		phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
		image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
		sliders: '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
		copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
		palette: '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
		eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
		power: '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
		clipboard: '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
		gauge: '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
		shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>'
	};

	/**
	 * Returns an SVG icon element.
	 */
	function icon( name, extraClass ) {
		var wrap = document.createElement( 'span' );
		// Constant markup only; never user data.
		wrap.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + ICONS[ name ] + '</svg>';
		var svg = wrap.firstChild;
		if ( extraClass ) {
			svg.setAttribute( 'class', extraClass );
		}
		return svg;
	}

	/**
	 * Creates an element. Children may be nodes or strings (added as text).
	 * Props: className, text, value, on* listeners, DOM properties, attributes.
	 */
	function h( tag, props, children ) {
		var el = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			var value = props[ key ];
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'className' ) {
				el.className = value;
			} else if ( key === 'text' ) {
				el.textContent = value;
			} else if ( key === 'value' ) {
				el.value = value;
			} else if ( key.indexOf( 'on' ) === 0 ) {
				el.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( key in el && typeof value !== 'string' ) {
				el[ key ] = value;
			} else {
				el.setAttribute( key, value === true ? '' : String( value ) );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			el.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
		} );
		return el;
	}

	/**
	 * Turns URLs in plain text into links, building DOM nodes only.
	 */
	function linkify( text ) {
		var fragment = document.createDocumentFragment();
		var pattern = /https?:\/\/[^\s<>"']+/g;
		var last = 0;
		var match;

		while ( ( match = pattern.exec( text ) ) !== null ) {
			var url = match[ 0 ].replace( /[.,!?;:)\]»،؛؟]+$/, '' );
			if ( match.index > last ) {
				fragment.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
			}
			fragment.appendChild( h( 'a', { href: url, target: '_blank', rel: 'nofollow noopener noreferrer', dir: 'ltr', text: url } ) );
			last = match.index + url.length;
			pattern.lastIndex = last;
		}

		if ( last < text.length ) {
			fragment.appendChild( document.createTextNode( text.slice( last ) ) );
		}

		return fragment;
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array( 16 );
		window.crypto.getRandomValues( bytes );
		bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
		bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
		var hex = Array.prototype.map.call( bytes, function ( b ) {
			return ( b + 0x100 ).toString( 16 ).slice( 1 );
		} ).join( '' );
		return hex.slice( 0, 8 ) + '-' + hex.slice( 8, 12 ) + '-' + hex.slice( 12, 16 ) + '-' + hex.slice( 16, 20 ) + '-' + hex.slice( 20 );
	}

	/**
	 * First letter of the first and last word. Punctuation is skipped, and a
	 * zero-width non-joiner keeps Persian letters from joining into a word.
	 */
	function initials( name ) {
		var letters = String( name || '' ).trim().split( /\s+/ ).map( function ( word ) {
			var match = word.match( /[\p{L}\p{N}]/u );
			return match ? match[ 0 ] : '';
		} ).filter( Boolean );

		if ( ! letters.length ) {
			return '';
		}

		var picked = letters.length > 1 ? [ letters[ 0 ], letters[ letters.length - 1 ] ] : [ letters[ 0 ] ];

		return picked.join( '\u200C' ).toUpperCase();
	}

	/**
	 * Date and number formatting. Persian locales get the Persian calendar
	 * and Persian digits through Intl, with no extra library.
	 */
	function createFormatter( locale, strings ) {
		var intlLocale = /^fa/i.test( locale || '' ) ? 'fa-IR-u-ca-persian' : ( locale || undefined );

		function dateFormat( options ) {
			try {
				return new Intl.DateTimeFormat( intlLocale, options );
			} catch ( e ) {
				return new Intl.DateTimeFormat( undefined, options );
			}
		}

		var time = dateFormat( { hour: '2-digit', minute: '2-digit' } );
		var day = dateFormat( { day: 'numeric', month: 'long' } );
		var fullDay = dateFormat( { day: 'numeric', month: 'long', year: 'numeric' } );
		var dateTime = dateFormat( { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' } );
		var numbers;
		var relative = null;

		try {
			numbers = new Intl.NumberFormat( intlLocale );
		} catch ( e ) {
			numbers = new Intl.NumberFormat();
		}

		try {
			relative = new Intl.RelativeTimeFormat( intlLocale, { numeric: 'auto', style: 'short' } );
		} catch ( e ) {
			relative = null;
		}

		function dayKey( date ) {
			return date.getFullYear() + '-' + date.getMonth() + '-' + date.getDate();
		}

		function number( n ) {
			return numbers.format( n );
		}

		return {
			dayKey: dayKey,
			number: number,
			time: function ( date ) {
				return time.format( date );
			},
			dateTime: function ( date ) {
				return dateTime.format( date );
			},
			format: function ( template, n ) {
				return String( template ).replace( '%d', number( n ) );
			},
			dayLabel: function ( date ) {
				var now = new Date();
				var yesterday = new Date( now.getTime() - 86400000 );
				if ( dayKey( date ) === dayKey( now ) ) {
					return strings.today;
				}
				if ( dayKey( date ) === dayKey( yesterday ) ) {
					return strings.yesterday;
				}
				return ( date.getFullYear() === now.getFullYear() ? day : fullDay ).format( date );
			},
			/**
			 * Short relative time for lists: "now", "5 min ago", "yesterday", then a date.
			 */
			ago: function ( date ) {
				var seconds = Math.round( ( date.getTime() - Date.now() ) / 1000 );
				var abs = Math.abs( seconds );
				if ( ! relative || abs >= 7 * 86400 ) {
					return day.format( date );
				}
				if ( abs < 60 ) {
					return relative.format( 0, 'second' );
				}
				if ( abs < 3600 ) {
					return relative.format( Math.round( seconds / 60 ), 'minute' );
				}
				if ( abs < 86400 ) {
					return relative.format( Math.round( seconds / 3600 ), 'hour' );
				}
				return relative.format( Math.round( seconds / 86400 ), 'day' );
			}
		};
	}

	function ApiError( status, code, message, data ) {
		this.status = status;
		this.code = code;
		this.message = message;
		this.data = data || {};
	}

	/**
	 * REST client for chatchi/v1. Works with pretty permalinks (/wp-json/)
	 * and plain ones (?rest_route=). Sends the WordPress nonce when given and
	 * continues without it if it has expired.
	 */
	function createApi( options ) {
		var state = { nonce: options.nonce || '' };

		function endpoint( path, query ) {
			var url = new URL( options.restUrl, window.location.href );
			var route = url.searchParams.get( 'rest_route' );

			if ( route !== null ) {
				url.searchParams.set( 'rest_route', route.replace( /\/$/, '' ) + path );
			} else {
				url.pathname = url.pathname.replace( /\/$/, '' ) + path;
			}

			Object.keys( query || {} ).forEach( function ( key ) {
				if ( query[ key ] !== undefined && query[ key ] !== null && query[ key ] !== '' ) {
					url.searchParams.set( key, query[ key ] );
				}
			} );

			return url.toString();
		}

		function request( method, path, opts, isRetry ) {
			opts = opts || {};
			var headers = { Accept: 'application/json' };

			if ( opts.body ) {
				headers[ 'Content-Type' ] = 'application/json';
			}
			if ( state.nonce ) {
				headers[ 'X-WP-Nonce' ] = state.nonce;
			}

			return fetch( endpoint( path, opts.query ), {
				method: method,
				headers: headers,
				credentials: 'same-origin',
				cache: 'no-store',
				body: opts.body ? JSON.stringify( opts.body ) : undefined
			} ).then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( data ) {
					if ( response.ok ) {
						return data;
					}

					var code = data && data.code ? data.code : 'http_' + response.status;

					if ( code === 'rest_cookie_invalid_nonce' && state.nonce && options.dropExpiredNonce && ! isRetry ) {
						state.nonce = '';
						return request( method, path, opts, true );
					}

					throw new ApiError( response.status, code, data && data.message ? data.message : options.networkError, data && data.data );
				} );
			}, function () {
				throw new ApiError( 0, 'network', options.networkError );
			} );
		}

		return { request: request, endpoint: endpoint };
	}

	window.ChatChiCore = {
		h: h,
		icon: icon,
		linkify: linkify,
		uuid: uuid,
		initials: initials,
		createFormatter: createFormatter,
		createApi: createApi,
		ApiError: ApiError
	};
}() );
