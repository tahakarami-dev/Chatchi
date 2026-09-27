/**
 * ChatChi chat widget.
 *
 * Build-free; uses the shared ChatChiCore (assets/shared/core.js). Renders
 * inside a Shadow DOM so the host theme cannot affect it. Talks to the
 * chatchi/v1 REST API.
 *
 * Security rule for this file: user-provided text (messages, names) is only
 * ever inserted with textContent / createTextNode, never innerHTML.
 */
( function () {
	'use strict';

	var config = window.ChatChiWidget;

	if ( ! config || ! window.ChatChiCore || ! window.fetch || ! Element.prototype.attachShadow || document.getElementById( 'chatchi-widget-host' ) ) {
		return;
	}

	var S = config.strings;
	var POLL_OPEN = 4000;
	var POLL_CLOSED = 30000;
	var POLL_HIDDEN = 60000;
	var POLL_MAX = 60000;
	var SESSION_EVERY = 5;
	var GROUP_GAP_MS = 2 * 60 * 1000;
	var TRAP_FIELD = 'website';
	var MIN_FORM_MS = 2100;
	var PAGE_SIZE = 30;
	var TYPING_EVERY = 3000;
	var MAX_TEXTAREA = 136;
	var EMOJI = [ '😀', '😂', '🥲', '😊', '😍', '🥰', '😘', '😉', '😎', '🤔', '🙄', '😅', '😢', '😭', '😡', '😱', '👍', '👎', '👏', '🙏', '🤝', '👋', '💪', '👌', '✌️', '🤞', '❤️', '💙', '💚', '💯', '🔥', '✨', '🎉', '🌹', '🌸', '☀️', '⭐', '✅', '❌', '❓', '❗', '📦', '🛒', '💳', '📞', '📍', '🎁', '⏰' ];

	var Core = window.ChatChiCore;
	var h = Core.h;
	var icon = Core.icon;
	var linkify = Core.linkify;
	var uuid = Core.uuid;
	var initials = Core.initials;

	var locale = config.locale || 'fa-IR';
	var fmt = Core.createFormatter( locale, S );
	var formatNumber = fmt.number;
	var format = fmt.format;
	var dayKey = fmt.dayKey;
	var dayLabel = fmt.dayLabel;
	var timeFormat = { format: fmt.time };

	// An expired nonce on a long-open page: continue as a guest instead of failing.
	var request = Core.createApi( {
		restUrl: config.restUrl,
		nonce: config.nonce,
		networkError: S.networkError,
		dropExpiredNonce: true
	} ).request;

	var api = {
		session: function () {
			return request( 'GET', '/widget/session' );
		},
		start: function ( profile ) {
			var body = Object.assign( { page_url: window.location.href }, profile );
			return request( 'POST', '/widget/conversations', { body: body } );
		},
		messages: function ( id, query ) {
			return request( 'GET', '/widget/conversations/' + id + '/messages', { query: query } );
		},
		send: function ( id, text, clientId ) {
			return request( 'POST', '/widget/conversations/' + id + '/messages', { body: { body: text, client_id: clientId } } );
		},
		read: function ( id, upTo ) {
			return request( 'POST', '/widget/conversations/' + id + '/read', { body: { up_to_id: upTo } } );
		},
		close: function ( id ) {
			return request( 'POST', '/widget/conversations/' + id + '/close' );
		},
		typing: function ( id, typing ) {
			return request( 'POST', '/widget/conversations/' + id + '/typing', { body: { typing: typing } } );
		}
	};

	/**
	 * Whether a message is only a few emoji, shown large without a bubble.
	 */
	var emojiOnly = ( function () {
		var pattern;

		try {
			pattern = new RegExp( '^(?:\\p{Extended_Pictographic}|\\p{Emoji_Modifier}|\\p{Regional_Indicator}|\\u200d|\\ufe0f|\\s)+$', 'u' );
		} catch ( e ) {
			return function () {
				return false;
			};
		}

		return function ( text ) {
			if ( ! text || text.length > 40 || ! pattern.test( text ) ) {
				return false;
			}
			// Joined sequences (family, flags) count as one each, roughly.
			var visible = Array.from( text.replace( /[\s\ufe0f]/g, '' ).split( '\u200d' ).join( '' ) );
			return visible.length > 0 && visible.length <= 3;
		};
	}() );

	/**
	 * A short, soft two-note chime made with Web Audio, so no sound file is
	 * downloaded. Browsers only allow sound after the visitor interacted with
	 * the page, so the audio context is unlocked when the chat is opened.
	 */
	var chime = {
		context: null,
		unlock: function () {
			var Context = window.AudioContext || window.webkitAudioContext;

			if ( ! config.sound || ! Context ) {
				return;
			}

			try {
				this.context = this.context || new Context();
				if ( this.context.state === 'suspended' ) {
					this.context.resume();
				}
			} catch ( e ) {
				this.context = null;
			}
		},
		play: function () {
			var context = this.context;

			if ( ! config.sound || ! context || context.state !== 'running' ) {
				return;
			}

			try {
				var now = context.currentTime;
				[ [ 880, 0 ], [ 1318.5, 0.13 ] ].forEach( function ( note ) {
					var oscillator = context.createOscillator();
					var gain = context.createGain();
					var at = now + note[ 1 ];

					oscillator.type = 'sine';
					oscillator.frequency.value = note[ 0 ];
					gain.gain.setValueAtTime( 0.0001, at );
					gain.gain.exponentialRampToValueAtTime( 0.12, at + 0.02 );
					gain.gain.exponentialRampToValueAtTime( 0.0001, at + 0.4 );
					oscillator.connect( gain );
					gain.connect( context.destination );
					oscillator.start( at );
					oscillator.stop( at + 0.45 );
				} );
			} catch ( e ) {
				// Sound is a nicety; never let it break the chat.
			}
		}
	};

	/* ------------------------------------------------------------------ *
	 * Widget
	 * ------------------------------------------------------------------ */

	function Widget() {
		this.state = {
			open: false,
			view: 'loading', // loading | error | form | chat
			errorMessage: '',
			session: null,
			visitor: null,
			conversation: null,
			closed: false,
			confirmingEnd: false,
			messages: [], // server messages, ascending by id
			pending: [], // { clientId, body, failed }
			hasMore: false,
			loadingEarlier: false,
			unread: 0,
			formErrors: {},
			formAlert: '',
			submitting: false,
			formValues: null,
			draft: '',
			agentTyping: false
		};
		this.lastReadMarked = 0;
		this.newIds = {};
		this.pollTimer = null;
		this.pollDelay = POLL_OPEN;
		this.pollCount = 0;
		this.booted = false;
		this.shellKey = null;
		this.typingSentAt = 0;
		this.newBelow = false;
		this.pageTitle = null;
		this.titleCount = 0;
	}

	Widget.prototype.mount = function () {
		var self = this;
		var host = h( 'div', { id: 'chatchi-widget-host' } );
		var shadow = host.attachShadow( { mode: 'open' } );

		// Keep the widget hidden until its stylesheet has loaded.
		shadow.appendChild( h( 'style', { text: ':host{all:initial}.chatchi:not(.is-ready){display:none}' } ) );

		var pendingStyles = config.styleUrls.length;
		config.styleUrls.forEach( function ( href ) {
			var link = h( 'link', { rel: 'stylesheet', href: href } );
			link.addEventListener( 'load', function () {
				pendingStyles--;
				if ( ! pendingStyles ) {
					self.root.classList.add( 'is-ready' );
				}
			} );
			shadow.appendChild( link );
		} );

		this.root = h( 'div', {
			className: 'chatchi chatchi--' + ( config.position === 'right' ? 'right' : 'left' ),
			dir: config.dir || 'rtl',
			lang: locale.split( '-' )[ 0 ]
		} );

		var colors = config.colors || {};
		this.root.style.setProperty( '--chatchi-primary', colors.primary );
		this.root.style.setProperty( '--chatchi-primary-hover', colors.primaryHover );
		this.root.style.setProperty( '--chatchi-primary-soft', colors.primarySoft );
		this.root.style.setProperty( '--chatchi-primary-contrast', colors.primaryContrast );

		this.badge = h( 'span', { className: 'chatchi-badge', hidden: true, 'aria-hidden': 'true' } );
		this.launcher = h( 'button', {
			type: 'button',
			className: 'chatchi-launcher',
			'aria-label': S.openChat,
			'aria-expanded': 'false',
			'aria-controls': 'chatchi-panel',
			onClick: function () {
				self.toggle();
			}
		}, [
			h( 'span', { className: 'chatchi-launcher__icon chatchi-launcher__icon--chat' }, [ icon( 'chat' ) ] ),
			h( 'span', { className: 'chatchi-launcher__icon chatchi-launcher__icon--close' }, [ icon( 'close' ) ] ),
			this.badge
		] );

		this.panel = h( 'div', {
			id: 'chatchi-panel',
			className: 'chatchi-panel',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-labelledby': 'chatchi-title'
		} );

		this.root.appendChild( this.panel );
		this.root.appendChild( this.launcher );
		shadow.appendChild( this.root );
		document.body.appendChild( host );

		this.root.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' || ! self.state.open ) {
				return;
			}
			event.stopPropagation();
			// Escape closes the emoji picker first, then the chat.
			if ( self.emojiOpen() ) {
				self.toggleEmoji( false );
				return;
			}
			self.close();
		} );

		// A click anywhere else in the widget closes the emoji picker.
		this.root.addEventListener( 'click', function ( event ) {
			var path = event.composedPath ? event.composedPath() : [];
			var inside = path.some( function ( node ) {
				return node.classList && ( node.classList.contains( 'chatchi-emoji' ) || node.classList.contains( 'chatchi-tool--emoji' ) );
			} );
			if ( ! inside && self.emojiOpen() ) {
				self.toggleEmoji( false );
			}
		} );

		// Escape also works when focus has fallen back to the page body,
		// without stealing it from the site's own dialogs.
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && self.state.open && ( document.activeElement === document.body || document.activeElement === host ) ) {
				self.close();
			}
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				self.restoreTitle();
				self.markRead();
				self.schedulePoll( 0 );
			}
		} );

		this.render();

		// Visitors who never chatted have nothing to restore: skip the session
		// request (a full WordPress load on the server) until they open the
		// chat. The readable marker cookie is set with the visitor identity;
		// logged-in users are identified by their account.
		if ( config.nonce || /(?:^|;\s*)chatchi_active=1/.test( document.cookie ) ) {
			this.boot();
		}
	};

	/* ---------------------------- state flow --------------------------- */

	Widget.prototype.set = function ( patch ) {
		Object.assign( this.state, patch );
		this.render();
	};

	Widget.prototype.boot = function () {
		var self = this;

		this.booting = true;

		return api.session().then( function ( session ) {
			self.sessionAt = Date.now();
			self.booted = true;
			self.booting = false;
			self.state.session = session;
			self.state.visitor = session.visitor;
			self.state.conversation = session.conversation;
			self.state.unread = session.unread_count || 0;
			self.state.closed = false;

			if ( session.conversation ) {
				return self.loadLatest().then( function () {
					self.set( { view: 'chat' } );
					self.schedulePoll();
				} );
			}

			self.set( { view: self.needsForm() ? 'form' : 'chat' } );
		} ).catch( function ( error ) {
			self.booting = false;
			self.set( { view: 'error', errorMessage: error.message || S.loadError } );
		} );
	};

	Widget.prototype.needsForm = function () {
		var session = this.state.session;
		return !! ( session && session.form && session.form.enabled && ! this.state.visitor );
	};

	Widget.prototype.toggle = function () {
		if ( this.state.open ) {
			this.close();
		} else {
			this.open();
		}
	};

	Widget.prototype.open = function () {
		// The bundled font is applied (and so downloaded) only once the chat
		// is used, not on every page view.
		this.root.classList.add( 'has-font' );
		chime.unlock();
		this.restoreTitle();
		this.set( { open: true } );
		this.launcher.setAttribute( 'aria-expanded', 'true' );
		this.launcher.setAttribute( 'aria-label', S.closeChat );
		this.lockScroll( true );

		if ( this.state.view === 'error' ) {
			this.set( { view: 'loading' } );
			this.boot();
		} else if ( ! this.booted && ! this.booting ) {
			this.boot();
		}

		this.scrollToBottom();
		this.markRead();
		this.focusDefault();
		this.schedulePoll( 0 );
	};

	Widget.prototype.close = function () {
		this.signalTyping( false );
		// Typing is only polled while open; do not show a stale indicator later.
		this.set( { open: false, confirmingEnd: false, agentTyping: false } );
		this.launcher.setAttribute( 'aria-expanded', 'false' );
		this.launcher.setAttribute( 'aria-label', S.openChat );
		this.lockScroll( false );
		this.launcher.focus();
		this.schedulePoll();
	};

	Widget.prototype.lockScroll = function ( lock ) {
		var isMobile = window.matchMedia && window.matchMedia( '(max-width: 639px)' ).matches;
		document.documentElement.style.overflow = lock && isMobile ? 'hidden' : '';
	};

	Widget.prototype.focusDefault = function () {
		var self = this;
		window.setTimeout( function () {
			var target = self.panel.querySelector( 'input, textarea' );
			if ( target && self.state.open ) {
				target.focus( { preventScroll: true } );
			}
		}, 50 );
	};

	/* ---------------------------- messages ----------------------------- */

	Widget.prototype.loadLatest = function () {
		var self = this;
		var id = this.state.conversation.id;

		return api.messages( id, { limit: PAGE_SIZE } ).then( function ( page ) {
			self.state.messages = page.items;
			self.state.hasMore = page.has_more;
		} );
	};

	Widget.prototype.loadEarlier = function () {
		var self = this;
		var first = this.state.messages[ 0 ];

		if ( ! first || this.state.loadingEarlier ) {
			return;
		}

		var log = this.panel.querySelector( '.chatchi-log' );
		var fromBottom = log ? log.scrollHeight - log.scrollTop : 0;

		this.set( { loadingEarlier: true } );

		api.messages( this.state.conversation.id, { before_id: first.id, limit: PAGE_SIZE } ).then( function ( page ) {
			self.state.messages = page.items.concat( self.state.messages );
			self.set( { hasMore: page.has_more, loadingEarlier: false } );
			var newLog = self.panel.querySelector( '.chatchi-log' );
			if ( newLog ) {
				newLog.scrollTop = newLog.scrollHeight - fromBottom;
			}
		} ).catch( function () {
			self.set( { loadingEarlier: false } );
		} );
	};

	Widget.prototype.lastId = function () {
		var messages = this.state.messages;
		return messages.length ? messages[ messages.length - 1 ].id : 0;
	};

	/**
	 * Adds server messages, dropping duplicates and settling pending ones.
	 */
	Widget.prototype.merge = function ( items ) {
		var self = this;
		var known = {};
		var arrived = 0;

		this.state.messages.forEach( function ( m ) {
			known[ m.id ] = true;
		} );

		items.forEach( function ( m ) {
			// Show who is answering right away, before the next session check.
			var conversation = self.state.conversation;
			if ( conversation && ! conversation.agent && m.sender.type === 'agent' && m.sender.name ) {
				conversation.agent = { id: null, name: m.sender.name, avatar_url: m.sender.avatar_url };
			}

			if ( ! known[ m.id ] ) {
				self.state.messages.push( m );
				self.newIds[ m.id ] = true;
				known[ m.id ] = true;
				if ( ! m.is_mine ) {
					arrived++;
				}
			}
			if ( m.client_id ) {
				self.state.pending = self.state.pending.filter( function ( p ) {
					return p.clientId !== m.client_id;
				} );
			}
		} );

		this.state.messages.sort( function ( a, b ) {
			return a.id - b.id;
		} );

		return arrived;
	};

	Widget.prototype.send = function ( text ) {
		var pending = { clientId: uuid(), body: text, failed: false };

		this.state.pending.push( pending );
		// Sending ends the typing signal on the server as well.
		this.typingSentAt = 0;
		this.set( { draft: '' } );
		this.scrollToBottom();
		this.deliver( pending );
	};

	Widget.prototype.deliver = function ( pending ) {
		var self = this;

		pending.failed = false;
		this.render();

		this.ensureConversation().then( function ( conversation ) {
			return api.send( conversation.id, pending.body, pending.clientId ).catch( function ( error ) {
				// One quiet retry after a server error; client_id prevents duplicates.
				if ( error.status >= 500 ) {
					return new Promise( function ( resolve ) {
						window.setTimeout( resolve, 400 );
					} ).then( function () {
						return api.send( conversation.id, pending.body, pending.clientId );
					} );
				}
				throw error;
			} );
		} ).then( function ( message ) {
			self.merge( [ message ] );
			self.render();
			self.scrollToBottom();
			self.schedulePoll();
		} ).catch( function ( error ) {
			if ( error.code === 'chatchi_conversation_closed' ) {
				self.state.pending = self.state.pending.filter( function ( p ) {
					return p !== pending;
				} );
				self.set( { closed: true, draft: pending.body } );
				return;
			}
			if ( error.code === 'chatchi_no_visitor' || error.code === 'chatchi_invalid_profile' ) {
				self.state.pending = self.state.pending.filter( function ( p ) {
					return p !== pending;
				} );
				self.state.visitor = null;
				self.state.conversation = null;
				self.set( { view: 'form', draft: pending.body, formErrors: ( error.data && error.data.fields ) || {} } );
				return;
			}
			pending.failed = true;
			pending.limited = error.code === 'chatchi_rate_limited';
			// Say why when the server refused the message itself (e.g. too long);
			// connection problems keep the generic "tap to retry".
			pending.reason = pending.limited ? self.waitMessage( error ) : ( error.status >= 400 && error.status < 500 && error.message ? error.message : '' );
			pending.debug = self.debugDetails( error );
			self.render();
		} );
	};

	/**
	 * Returns the open conversation, starting one first if needed.
	 */
	Widget.prototype.ensureConversation = function () {
		var self = this;

		if ( this.state.conversation && ! this.state.closed ) {
			return Promise.resolve( this.state.conversation );
		}

		if ( ! this.startPromise ) {
			var visitor = this.state.visitor || {};
			this.startPromise = this.startConversation( { name: visitor.name, email: visitor.email, phone: visitor.phone } ).then( function ( result ) {
				self.state.visitor = result.visitor;
				self.state.conversation = result.conversation;
				self.state.closed = false;
				self.state.messages = [];
				self.state.hasMore = false;
				self.startPromise = null;
				return result.conversation;
			}, function ( error ) {
				self.startPromise = null;
				throw error;
			} );
		}

		return this.startPromise;
	};

	/**
	 * Starts (or resumes) a conversation, with the bot checks' form token.
	 *
	 * A start sent sooner than a person could fill in the form, or with a
	 * token from a page left open for a day, is refused by the server; both
	 * are retried here, so real visitors never see those errors. A renewed
	 * token is itself brand new, so an expired token can need two retries:
	 * renew, then wait.
	 */
	Widget.prototype.startConversation = function ( profile, trap, attempt ) {
		var self = this;
		var session = this.state.session || {};
		var body = Object.assign( { form_token: session.form_token || '' }, profile );

		body[ TRAP_FIELD ] = trap || '';

		attempt = attempt || 0;

		// The form token must be a couple of seconds old (bot check). Rather
		// than being refused, wait out the rest when the form was very quick.
		var early = attempt ? 0 : Math.max( 0, ( this.sessionAt || 0 ) + MIN_FORM_MS - Date.now() );
		var ready = early ? new Promise( function ( resolve ) {
			window.setTimeout( resolve, early );
		} ) : Promise.resolve();

		return ready.then( function () {
			return api.start( body );
		} ).catch( function ( error ) {
			if ( attempt >= 2 ) {
				throw error;
			}
			if ( error.status >= 500 ) {
				// The server may have just repaired itself (see Support\Failure);
				// starting again returns the same conversation if one was made.
				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, 400 );
				} ).then( function () {
					return self.startConversation( profile, trap, attempt + 1 );
				} );
			}
			if ( error.code === 'chatchi_too_fast' ) {
				var wait = Math.max( 1, ( error.data && error.data.retry_after ) || 1 ) * 1000;
				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, wait );
				} ).then( function () {
					return self.startConversation( profile, trap, attempt + 1 );
				} );
			}
			if ( error.code === 'chatchi_form_expired' ) {
				return api.session().then( function ( fresh ) {
					if ( self.state.session ) {
						self.state.session.form_token = fresh.form_token;
					}
					return self.startConversation( profile, trap, attempt + 1 );
				} );
			}
			throw error;
		} );
	};

	/**
	 * Technical details of a failed request, for administrators only: the
	 * HTTP status, the error code and the server's own explanation.
	 */
	Widget.prototype.debugDetails = function ( error ) {
		if ( ! config.debug || ! error ) {
			return '';
		}

		var data = error.data || {};
		var parts = [ error.status ? 'HTTP ' + error.status : 'HTTP —', error.code || '' ];

		if ( data.details ) {
			parts.push( data.details );
		} else if ( data.raw ) {
			parts.push( data.raw );
		}

		return parts.filter( Boolean ).join( ' · ' );
	};

	/**
	 * Turns a rate-limit error into "try again in N seconds/minutes".
	 */
	Widget.prototype.waitMessage = function ( error ) {
		var seconds = Math.max( 1, Math.ceil( ( error.data && error.data.retry_after ) || 1 ) );

		if ( seconds >= 90 ) {
			return fmt.format( S.waitMinutes, Math.ceil( seconds / 60 ) );
		}

		return fmt.format( S.waitSeconds, seconds );
	};

	Widget.prototype.submitForm = function ( form ) {
		var self = this;
		var data = {};

		[ 'name', 'email', 'phone' ].forEach( function ( field ) {
			if ( form.elements[ field ] ) {
				data[ field ] = form.elements[ field ].value;
			}
		} );

		this.state.formValues = data;
		this.set( { submitting: true, formErrors: {}, formAlert: '' } );

		var trap = form.elements[ TRAP_FIELD ] ? form.elements[ TRAP_FIELD ].value : '';

		this.startConversation( data, trap ).then( function ( result ) {
			self.state.visitor = result.visitor;
			self.state.conversation = result.conversation;
			self.state.closed = false;
			return self.loadLatest();
		} ).then( function () {
			self.set( { view: 'chat', submitting: false } );
			self.scrollToBottom();
			self.focusDefault();
			self.schedulePoll();
		} ).catch( function ( error ) {
			var fields = ( error.data && error.data.fields ) || {};
			var alert = error.code === 'chatchi_rate_limited' ? self.waitMessage( error ) : error.message;
			self.set( { submitting: false, formErrors: fields, formAlert: Object.keys( fields ).length ? '' : alert, formDebug: self.debugDetails( error ) } );
			var invalid = self.panel.querySelector( '[aria-invalid="true"]' );
			if ( invalid ) {
				invalid.focus();
			}
		} );
	};

	Widget.prototype.markRead = function () {
		var self = this;
		var conversation = this.state.conversation;
		var lastId = this.lastId();

		if ( ! conversation || ! this.state.open || document.hidden || lastId <= this.lastReadMarked ) {
			return;
		}

		var hasUnread = this.state.messages.some( function ( m ) {
			return ! m.is_mine && ! m.read_at && m.id > self.lastReadMarked;
		} );

		this.lastReadMarked = lastId;

		if ( this.state.unread ) {
			this.set( { unread: 0 } );
		}

		if ( hasUnread ) {
			api.read( conversation.id, lastId ).catch( function () {
				self.lastReadMarked = 0;
			} );
		}
	};

	Widget.prototype.endConversation = function () {
		var self = this;
		var conversation = this.state.conversation;

		if ( ! conversation ) {
			return;
		}

		api.close( conversation.id ).then( function ( updated ) {
			self.state.conversation = updated;
			self.set( { closed: true, confirmingEnd: false } );
		} ).catch( function ( error ) {
			self.set( { confirmingEnd: false } );
			window.alert( error.message );
		} );
	};

	Widget.prototype.startNew = function () {
		this.state.conversation = null;
		this.state.messages = [];
		this.state.pending = [];
		this.state.hasMore = false;
		this.lastReadMarked = 0;
		this.set( { closed: false, view: this.needsForm() ? 'form' : 'chat' } );
		this.focusDefault();
	};

	/* ------------------------------ polling ---------------------------- */

	Widget.prototype.schedulePoll = function ( delay ) {
		var self = this;

		window.clearTimeout( this.pollTimer );

		if ( ! this.state.conversation || this.state.closed ) {
			return;
		}

		if ( delay === undefined ) {
			delay = document.hidden ? POLL_HIDDEN : ( this.state.open ? POLL_OPEN : POLL_CLOSED );
		}

		this.pollTimer = window.setTimeout( function () {
			self.poll();
		}, delay );
	};

	Widget.prototype.poll = function () {
		var self = this;
		var conversation = this.state.conversation;

		if ( ! conversation || this.state.closed ) {
			return;
		}

		this.pollCount++;

		// While the panel is closed only the unread count matters; every few ticks
		// while open, the session also tells whether an agent closed the chat.
		var checkSession = ! this.state.open || this.pollCount % SESSION_EVERY === 0;
		var work = this.state.open ? this.pollMessages() : Promise.resolve();

		work.then( function () {
			return checkSession ? api.session() : null;
		} ).then( function ( session ) {
			if ( session ) {
				self.applySession( session );
			}
			self.pollDelay = POLL_OPEN;
			self.schedulePoll();
		} ).catch( function ( error ) {
			if ( error.status === 401 ) {
				self.boot();
				return;
			}
			self.pollDelay = Math.min( POLL_MAX, self.pollDelay * 2 );
			self.schedulePoll( self.pollDelay );
		} );
	};

	Widget.prototype.pollMessages = function () {
		var self = this;
		var id = this.state.conversation.id;

		return api.messages( id, { after_id: this.lastId(), limit: 50 } ).then( function ( page ) {
			var typing = !! page.typing && ! page.items.length;

			if ( page.items.length ) {
				var atBottom = self.isAtBottom();
				var arrived = self.merge( page.items );
				self.state.agentTyping = typing;
				self.render();
				if ( atBottom ) {
					self.scrollToBottom();
				} else if ( arrived ) {
					self.newBelow = true;
					self.updateJump();
				}
				if ( arrived ) {
					self.notify( arrived );
				}
				self.markRead();
			} else if ( typing !== self.state.agentTyping ) {
				var wasAtBottom = self.isAtBottom();
				self.set( { agentTyping: typing } );
				if ( wasAtBottom ) {
					self.scrollToBottom();
				}
			}
			if ( page.has_more ) {
				return self.pollMessages();
			}
		} );
	};

	Widget.prototype.applySession = function ( session ) {
		var current = this.state.conversation;

		if ( ! session.conversation || ( current && session.conversation.id !== current.id ) ) {
			if ( current ) {
				this.set( { closed: true } );
			}
			return;
		}

		var unread = this.state.open ? 0 : session.unread_count;
		var changed = JSON.stringify( session.conversation ) !== JSON.stringify( current ) || unread !== this.state.unread;

		if ( unread > this.state.unread ) {
			this.notify( unread - this.state.unread );
		}

		if ( changed ) {
			this.state.conversation = session.conversation;
			this.set( { unread: unread } );
		}
	};

	/**
	 * Tells the visitor about new replies they are not looking at: a soft
	 * chime, and the count in the page title while the tab is in the
	 * background.
	 */
	Widget.prototype.notify = function ( count ) {
		if ( this.state.open && ! document.hidden ) {
			return;
		}

		chime.play();

		if ( document.hidden ) {
			if ( this.pageTitle === null ) {
				this.pageTitle = document.title;
			}
			this.titleCount += count;
			document.title = '(' + formatNumber( this.titleCount ) + ') ' + S.newMessage + ' — ' + this.pageTitle;
		}
	};

	Widget.prototype.restoreTitle = function () {
		if ( this.pageTitle !== null ) {
			document.title = this.pageTitle;
			this.pageTitle = null;
		}
		this.titleCount = 0;
	};

	/**
	 * Lets the agent see that the visitor is typing. Sent at most every few
	 * seconds while typing, and once when the text is cleared.
	 */
	Widget.prototype.signalTyping = function ( typing ) {
		var conversation = this.state.conversation;
		var now = Date.now();

		if ( ! conversation || this.state.closed ) {
			return;
		}

		if ( typing ) {
			if ( now - this.typingSentAt < TYPING_EVERY ) {
				return;
			}
			this.typingSentAt = now;
		} else {
			if ( ! this.typingSentAt ) {
				return;
			}
			this.typingSentAt = 0;
		}

		api.typing( conversation.id, typing ).catch( function () {
			// A lost typing signal only hides an indicator; nothing to retry.
		} );
	};

	/* ------------------------------ render ----------------------------- */

	Widget.prototype.isAtBottom = function () {
		var log = this.panel.querySelector( '.chatchi-log' );
		return ! log || log.scrollHeight - log.scrollTop - log.clientHeight < 40;
	};

	Widget.prototype.scrollToBottom = function ( smooth ) {
		var self = this;
		window.requestAnimationFrame( function () {
			var log = self.panel.querySelector( '.chatchi-log' );
			if ( log ) {
				if ( smooth && log.scrollTo ) {
					log.scrollTo( { top: log.scrollHeight, behavior: 'smooth' } );
				} else {
					log.scrollTop = log.scrollHeight;
				}
			}
			self.newBelow = false;
			self.updateJump();
		} );
	};

	/**
	 * Shows the "latest message" button while the visitor reads older
	 * messages, with a dot when new ones arrived below.
	 */
	Widget.prototype.updateJump = function () {
		var jump = this.panel.querySelector( '.chatchi-jump' );
		var log = this.panel.querySelector( '.chatchi-log' );

		if ( ! jump || ! log ) {
			return;
		}

		var away = log.scrollHeight - log.scrollTop - log.clientHeight > 160;

		if ( ! away ) {
			this.newBelow = false;
		}

		jump.classList.toggle( 'is-visible', away );
		jump.classList.toggle( 'has-new', away && this.newBelow );
		jump.tabIndex = away ? 0 : -1;
	};

	/**
	 * Updates the DOM from state.
	 *
	 * The panel is only rebuilt when its structure changes (view, closed state,
	 * confirmation, agent, form state). Otherwise only the message list is
	 * replaced, so typing — including IME composition for Persian keyboards —
	 * is never interrupted by incoming messages.
	 */
	Widget.prototype.render = function () {
		var state = this.state;

		this.root.classList.toggle( 'is-open', state.open );

		var showBadge = ! state.open && state.unread > 0;
		this.badge.hidden = ! showBadge;
		this.badge.textContent = showBadge ? formatNumber( Math.min( state.unread, 99 ) ) : '';
		this.launcher.setAttribute( 'aria-label', ( state.open ? S.closeChat : S.openChat ) + ( showBadge ? ' — ' + format( S.unread, state.unread ) : '' ) );

		var agent = state.conversation && state.conversation.agent;
		var shellKey = JSON.stringify( [
			state.view,
			state.closed,
			state.confirmingEnd,
			agent ? [ agent.id, agent.name, agent.avatar_url ] : null,
			!! state.conversation,
			state.view === 'form' ? [ state.formErrors, state.formAlert, state.submitting ] : null,
			state.view === 'error' ? state.errorMessage : null
		] );

		if ( shellKey !== this.shellKey ) {
			this.shellKey = shellKey;
			this.renderShell();
		} else if ( state.view === 'chat' ) {
			this.renderLogOnly();
		}

		this.renderSubtitle();
		this.newIds = {};
	};

	/**
	 * The header subtitle doubles as the typing status, updated in place so
	 * the composer is never rebuilt while the visitor types.
	 */
	Widget.prototype.renderSubtitle = function () {
		var subtitle = this.panel.querySelector( '.chatchi-subtitle' );
		var state = this.state;
		var agent = state.conversation && state.conversation.agent;
		var typing = state.view === 'chat' && state.agentTyping && ! state.closed;

		if ( ! subtitle ) {
			return;
		}

		subtitle.textContent = typing ? S.typing : ( agent ? S.support : S.subtitle );
		subtitle.classList.toggle( 'is-typing', typing );
	};

	Widget.prototype.renderShell = function () {
		// The draft lives in state (kept current by the textarea's input event).
		var textarea = this.panel.querySelector( '.chatchi-textarea' );
		var hadFocus = textarea && this.root.getRootNode().activeElement === textarea;

		var log = this.panel.querySelector( '.chatchi-log' );
		var scrollTop = log ? log.scrollTop : null;

		this.panel.replaceChildren( this.renderHeader(), this.renderBody() );

		var newLog = this.panel.querySelector( '.chatchi-log' );
		if ( newLog && scrollTop !== null ) {
			newLog.scrollTop = scrollTop;
		}

		var newTextarea = this.panel.querySelector( '.chatchi-textarea' );
		if ( hadFocus && newTextarea ) {
			newTextarea.focus( { preventScroll: true } );
			newTextarea.setSelectionRange( newTextarea.value.length, newTextarea.value.length );
		}
	};

	Widget.prototype.renderLogOnly = function () {
		var log = this.panel.querySelector( '.chatchi-log' );

		if ( ! log ) {
			this.renderShell();
			return;
		}

		var scrollTop = log.scrollTop;
		var fresh = this.renderLog();

		log.replaceWith( fresh );
		fresh.scrollTop = scrollTop;
	};

	Widget.prototype.renderHeader = function () {
		var self = this;
		var state = this.state;
		var agent = state.conversation && state.conversation.agent;
		var title = agent ? agent.name : S.title;
		var canEnd = state.view === 'chat' && state.conversation && ! state.closed;

		return h( 'div', { className: 'chatchi-header' }, [
			agent ? this.renderAvatar( agent ) : h( 'span', { className: 'chatchi-avatar', 'aria-hidden': 'true' }, [ icon( 'headset' ) ] ),
			h( 'div', { className: 'chatchi-header__text' }, [
				h( 'h2', { id: 'chatchi-title', className: 'chatchi-title', text: title } ),
				h( 'p', { className: 'chatchi-subtitle', 'aria-live': 'polite', text: agent ? S.support : S.subtitle } )
			] ),
			h( 'div', { className: 'chatchi-header__actions' }, [
				canEnd ? h( 'button', {
					type: 'button',
					className: 'chatchi-text-button',
					text: S.endChat,
					onClick: function () {
						self.set( { confirmingEnd: true } );
					}
				} ) : null,
				h( 'button', {
					type: 'button',
					className: 'chatchi-icon-button',
					'aria-label': S.closeChat,
					onClick: function () {
						self.close();
					}
				}, [ icon( 'close' ) ] )
			] )
		] );
	};

	Widget.prototype.renderAvatar = function ( agent, small ) {
		var className = 'chatchi-avatar' + ( small ? ' chatchi-avatar--sm' : '' );

		if ( agent && agent.avatar_url ) {
			return h( 'span', { className: className }, [ h( 'img', { src: agent.avatar_url, alt: '' } ) ] );
		}

		return h( 'span', { className: className, 'aria-hidden': 'true', text: initials( agent ? agent.name : S.support ) } );
	};

	Widget.prototype.renderBody = function () {
		var state = this.state;
		var body = h( 'div', { className: 'chatchi-body' } );

		if ( state.view === 'loading' ) {
			body.appendChild( h( 'div', { className: 'chatchi-skeleton', 'aria-busy': 'true', 'aria-label': S.loading }, [ h( 'span' ), h( 'span' ), h( 'span' ) ] ) );
		} else if ( state.view === 'error' ) {
			body.appendChild( this.renderError() );
		} else if ( state.view === 'form' ) {
			body.appendChild( this.renderForm() );
		} else {
			var self = this;
			body.appendChild( h( 'div', { className: 'chatchi-log-wrap' }, [
				this.renderLog(),
				h( 'button', {
					type: 'button',
					className: 'chatchi-jump',
					tabindex: '-1',
					'aria-label': S.jumpLatest,
					title: S.jumpLatest,
					onClick: function () {
						self.scrollToBottom( true );
					}
				}, [ icon( 'chevronDown' ) ] )
			] ) );
			if ( state.confirmingEnd ) {
				body.appendChild( this.renderConfirm() );
			}
			body.appendChild( this.renderFooter() );
		}

		return body;
	};

	Widget.prototype.renderError = function () {
		var self = this;

		return h( 'div', { className: 'chatchi-state', role: 'alert' }, [
			icon( 'alert' ),
			h( 'p', { text: this.state.errorMessage || S.loadError } ),
			h( 'button', {
				type: 'button',
				className: 'chatchi-button chatchi-button--secondary',
				text: S.retry,
				onClick: function () {
					self.set( { view: 'loading' } );
					self.boot();
				}
			} )
		] );
	};

	Widget.prototype.renderForm = function () {
		var self = this;
		var state = this.state;
		var fields = ( state.session && state.session.form && state.session.form.fields ) || {};
		var values = Object.assign( {}, state.visitor || {}, state.formValues || {} );
		var specs = {
			name: { type: 'text', autocomplete: 'name', label: S.name },
			email: { type: 'email', autocomplete: 'email', label: S.email, dir: 'ltr', inputmode: 'email' },
			phone: { type: 'tel', autocomplete: 'tel', label: S.phone, dir: 'ltr', inputmode: 'tel' }
		};

		var form = h( 'form', {
			className: 'chatchi-form',
			noValidate: true,
			onSubmit: function ( event ) {
				event.preventDefault();
				self.submitForm( form );
			}
		}, [
			config.welcome ? this.renderMessage( this.welcomeEntry( new Date() ), true, true ) : null,
			h( 'p', { className: 'chatchi-form__intro', text: S.formIntro } )
		] );

		var card = h( 'div', { className: 'chatchi-form__card' } );
		form.appendChild( card );

		Object.keys( specs ).forEach( function ( name ) {
			var field = fields[ name ];
			if ( ! field || ! field.enabled ) {
				return;
			}
			var spec = specs[ name ];
			var error = state.formErrors[ name ];
			var inputId = 'chatchi-field-' + name;

			card.appendChild( h( 'div', { className: 'chatchi-field' }, [
				h( 'label', { className: 'chatchi-label', for: inputId }, [
					spec.label,
					field.required ? null : h( 'small', { text: ' (' + S.optional + ')' } )
				] ),
				h( 'input', {
					id: inputId,
					name: name,
					className: 'chatchi-input',
					type: spec.type,
					autocomplete: spec.autocomplete,
					inputmode: spec.inputmode,
					dir: spec.dir,
					required: field.required,
					value: values[ name ] || '',
					'aria-invalid': error ? 'true' : null,
					'aria-describedby': error ? inputId + '-error' : null
				} ),
				error ? h( 'p', { id: inputId + '-error', className: 'chatchi-field-error', text: error } ) : null
			] ) );
		} );

		// Honeypot: invisible to people and screen readers; bots fill it in.
		card.appendChild( h( 'div', { className: 'chatchi-trap', 'aria-hidden': 'true' }, [
			h( 'label', { for: 'chatchi-field-' + TRAP_FIELD, text: 'Website' } ),
			h( 'input', { id: 'chatchi-field-' + TRAP_FIELD, name: TRAP_FIELD, type: 'text', tabindex: '-1', autocomplete: 'off' } )
		] ) );

		if ( state.formAlert ) {
			card.appendChild( h( 'p', { className: 'chatchi-alert', role: 'alert', text: state.formAlert } ) );
			if ( state.formDebug ) {
				card.appendChild( h( 'p', { className: 'chatchi-debug', dir: 'ltr', text: state.formDebug } ) );
			}
		}

		card.appendChild( h( 'button', { type: 'submit', className: 'chatchi-button', disabled: state.submitting, text: state.submitting ? S.loading : S.start } ) );

		return form;
	};

	Widget.prototype.renderLog = function () {
		var self = this;
		var state = this.state;
		var log = h( 'div', { className: 'chatchi-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', tabindex: '0' } );

		log.addEventListener( 'scroll', function () {
			self.updateJump();
		}, { passive: true } );

		if ( state.hasMore ) {
			log.appendChild( h( 'button', {
				type: 'button',
				className: 'chatchi-earlier',
				disabled: state.loadingEarlier,
				text: state.loadingEarlier ? S.loading : S.earlier,
				onClick: function () {
					self.loadEarlier();
				}
			} ) );
		}

		var entries = state.messages.map( function ( m ) {
			return {
				key: m.id,
				mine: m.is_mine,
				type: m.sender.type,
				sender: m.sender,
				body: m.body,
				date: new Date( m.created_at ),
				read: !! m.read_at,
				isNew: !! self.newIds[ m.id ]
			};
		} ).concat( state.pending.map( function ( p ) {
			return { key: p.clientId, mine: true, type: 'visitor', body: p.body, date: new Date(), pending: ! p.failed, failed: p.failed, ref: p, isNew: true };
		} ) );

		// The welcome message opens the conversation once its start is loaded.
		if ( config.welcome && ! state.hasMore ) {
			var started = state.conversation && state.conversation.created_at ? new Date( state.conversation.created_at ) : null;
			entries.unshift( this.welcomeEntry( started || ( entries[ 0 ] ? entries[ 0 ].date : new Date() ) ) );
		}

		if ( ! entries.length ) {
			log.appendChild( h( 'div', { className: 'chatchi-state' }, [ h( 'p', { text: S.empty } ) ] ) );
			return log;
		}

		var lastDay = null;

		entries.forEach( function ( entry, i ) {
			var prev = entries[ i - 1 ];
			var next = entries[ i + 1 ];
			var day = dayKey( entry.date );

			if ( day !== lastDay ) {
				log.appendChild( h( 'div', { className: 'chatchi-day', text: dayLabel( entry.date ) } ) );
				lastDay = day;
			}

			var groupStart = ! prev || ! self.sameGroup( prev, entry );
			var groupEnd = ! next || ! self.sameGroup( entry, next );

			log.appendChild( self.renderMessage( entry, groupStart, groupEnd ) );
		} );

		if ( state.agentTyping && ! state.closed ) {
			log.appendChild( this.renderTyping() );
		}

		return log;
	};

	/**
	 * Three bouncing dots from the agent while they write a reply.
	 */
	Widget.prototype.renderTyping = function () {
		var agent = this.state.conversation && this.state.conversation.agent;
		var label = agent && agent.name ? String( S.typingNamed ).replace( '%s', agent.name ) : S.typing;

		return h( 'div', { className: 'chatchi-row chatchi-row--theirs chatchi-row--typing is-group-start is-group-end is-new' }, [
			this.renderAvatar( agent || null, true ),
			h( 'div', { className: 'chatchi-stack' }, [
				h( 'div', { className: 'chatchi-bubble', role: 'status', 'aria-label': label }, [
					h( 'span', { className: 'chatchi-dot' } ),
					h( 'span', { className: 'chatchi-dot' } ),
					h( 'span', { className: 'chatchi-dot' } )
				] )
			] )
		] );
	};

	/**
	 * The configured welcome message, shown as the first bubble from support.
	 * It exists only in the widget and is never stored as a message.
	 */
	Widget.prototype.welcomeEntry = function ( date ) {
		return {
			key: 'welcome',
			mine: false,
			type: 'welcome',
			sender: { name: S.support, avatar_url: null },
			body: config.welcome,
			date: date
		};
	};

	Widget.prototype.sameGroup = function ( a, b ) {
		return a.mine === b.mine &&
			a.type === b.type &&
			( a.sender && b.sender ? a.sender.name === b.sender.name : true ) &&
			dayKey( a.date ) === dayKey( b.date ) &&
			Math.abs( b.date - a.date ) < GROUP_GAP_MS;
	};

	Widget.prototype.renderMessage = function ( entry, groupStart, groupEnd ) {
		var self = this;

		if ( entry.type === 'system' ) {
			return h( 'div', { className: 'chatchi-row chatchi-row--system' }, [ entry.body ] );
		}

		var classes = [ 'chatchi-row', entry.mine ? 'chatchi-row--mine' : 'chatchi-row--theirs' ];
		if ( entry.type === 'welcome' ) {
			classes.push( 'chatchi-row--welcome' );
		}
		if ( groupStart ) {
			classes.push( 'is-group-start' );
		}
		if ( groupEnd ) {
			classes.push( 'is-group-end' );
		}
		if ( entry.isNew ) {
			classes.push( 'is-new' );
		}
		if ( entry.pending ) {
			classes.push( 'is-pending' );
		}
		if ( entry.failed ) {
			classes.push( 'is-failed' );
		}

		var stack = h( 'div', { className: 'chatchi-stack' } );

		if ( ! entry.mine && groupStart && entry.sender && entry.sender.name ) {
			stack.appendChild( h( 'span', { className: 'chatchi-sender', text: entry.sender.name } ) );
		}

		var bubble = h( 'div', { className: 'chatchi-bubble' + ( emojiOnly( entry.body ) ? ' is-emoji' : '' ) } );
		bubble.appendChild( linkify( entry.body ) );
		stack.appendChild( bubble );

		if ( entry.failed ) {
			stack.appendChild( h( 'button', {
				type: 'button',
				className: 'chatchi-retry',
				text: entry.ref.reason || S.sendFailed,
				onClick: function () {
					self.deliver( entry.ref );
					self.focusDefault();
				}
			} ) );
			if ( entry.ref.debug ) {
				stack.appendChild( h( 'p', { className: 'chatchi-debug', dir: 'ltr', text: entry.ref.debug } ) );
			}
		} else if ( groupEnd && entry.type !== 'welcome' ) {
			stack.appendChild( this.renderMeta( entry ) );
		}

		var row = h( 'div', { className: classes.join( ' ' ) } );

		if ( ! entry.mine ) {
			var avatar = this.renderAvatar( entry.sender, true );
			if ( ! groupEnd ) {
				avatar.classList.add( 'chatchi-avatar--spacer' );
			}
			row.appendChild( avatar );
		}

		row.appendChild( stack );

		return row;
	};

	Widget.prototype.renderMeta = function ( entry ) {
		var children = [ h( 'time', { text: timeFormat.format( entry.date ) } ) ];
		var className = 'chatchi-meta';

		if ( entry.mine ) {
			if ( entry.pending ) {
				children.push( icon( 'clock' ), h( 'span', { className: 'chatchi-sr', text: S.sending } ) );
			} else if ( entry.read ) {
				className += ' is-read';
				children.push( icon( 'checks' ), h( 'span', { className: 'chatchi-sr', text: S.read } ) );
			} else {
				children.push( icon( 'check' ), h( 'span', { className: 'chatchi-sr', text: S.sent } ) );
			}
		}

		return h( 'div', { className: className }, children );
	};

	Widget.prototype.renderConfirm = function () {
		var self = this;

		return h( 'div', { className: 'chatchi-confirm', role: 'alertdialog', 'aria-label': S.endConfirm }, [
			h( 'span', { text: S.endConfirm } ),
			h( 'div', {}, [
				h( 'button', {
					type: 'button',
					className: 'chatchi-text-button',
					text: S.endNo,
					onClick: function () {
						self.set( { confirmingEnd: false } );
					}
				} ),
				h( 'button', {
					type: 'button',
					className: 'chatchi-text-button chatchi-text-button--danger',
					text: S.endYes,
					onClick: function () {
						self.endConversation();
					}
				} )
			] )
		] );
	};

	Widget.prototype.renderFooter = function () {
		var self = this;
		var state = this.state;
		var max = ( state.session && state.session.max_message_length ) || 2000;

		if ( state.closed ) {
			return h( 'div', { className: 'chatchi-footer' }, [
				h( 'div', { className: 'chatchi-notice', role: 'status' }, [
					h( 'p', { text: S.closed } ),
					h( 'button', {
						type: 'button',
						className: 'chatchi-button',
						text: S.newChat,
						onClick: function () {
							self.startNew();
						}
					} )
				] )
			] );
		}

		var counter = h( 'p', { className: 'chatchi-counter', 'aria-live': 'polite', hidden: true } );
		var sendButton = h( 'button', { type: 'submit', className: 'chatchi-send', 'aria-label': S.send, title: S.send, disabled: ! state.draft.trim() }, [ icon( 'send', 'chatchi-flip' ) ] );
		// No maxlength: it counts UTF-16 units, so emoji would use up two
		// characters each. The limit is shown and enforced in code points,
		// as the server counts them.
		var textarea = h( 'textarea', {
			className: 'chatchi-textarea',
			rows: '1',
			placeholder: S.placeholder,
			'aria-label': S.placeholder,
			enterkeyhint: 'send',
			value: state.draft
		} );
		var emojiButton = h( 'button', {
			type: 'button',
			className: 'chatchi-tool chatchi-tool--emoji',
			'aria-label': S.emoji,
			title: S.emoji,
			'aria-expanded': 'false',
			'aria-controls': 'chatchi-emoji',
			onClick: function () {
				self.toggleEmoji();
			}
		}, [ icon( 'smile' ) ] );
		var box = h( 'div', { className: 'chatchi-composer__box' }, [ emojiButton, textarea, sendButton ] );
		var picker = h( 'div', { id: 'chatchi-emoji', className: 'chatchi-emoji', role: 'group', 'aria-label': S.emoji, hidden: true }, EMOJI.map( function ( emoji ) {
			return h( 'button', {
				type: 'button',
				text: emoji,
				'aria-label': emoji,
				onClick: function () {
					self.insertText( textarea, emoji );
				}
			} );
		} ) );

		function refresh() {
			var used = Array.from( textarea.value ).length;
			var left = max - used;
			var over = left < 0;

			sendButton.disabled = ! textarea.value.trim() || over;
			box.classList.toggle( 'is-over', over );
			counter.hidden = left > 200;
			counter.classList.toggle( 'is-warning', left <= 50 && ! over );
			counter.classList.toggle( 'is-over', over );
			counter.textContent = format( S.charactersLeft, Math.max( 0, left ) );
			textarea.style.height = 'auto';
			textarea.style.height = Math.min( textarea.scrollHeight, MAX_TEXTAREA ) + 'px';
			textarea.style.overflowY = textarea.scrollHeight > MAX_TEXTAREA ? 'auto' : 'hidden';
		}

		function submit() {
			var text = textarea.value.trim();
			if ( ! text || Array.from( text ).length > max ) {
				return;
			}
			textarea.value = '';
			self.state.draft = '';
			self.toggleEmoji( false );
			refresh();
			self.send( text );
			textarea.focus( { preventScroll: true } );
		}

		textarea.addEventListener( 'input', function () {
			self.state.draft = textarea.value;
			refresh();
			self.signalTyping( !! textarea.value.trim() );
		} );

		textarea.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				submit();
			}
		} );

		var form = h( 'form', {
			className: 'chatchi-composer',
			onSubmit: function ( event ) {
				event.preventDefault();
				submit();
			}
		}, [ box ] );

		window.requestAnimationFrame( refresh );

		return h( 'div', { className: 'chatchi-footer' }, [
			picker,
			form,
			h( 'div', { className: 'chatchi-composer__meta' }, [
				h( 'span', { className: 'chatchi-hint', 'aria-hidden': 'true', text: S.sendHint } ),
				counter
			] )
		] );
	};

	Widget.prototype.emojiOpen = function () {
		var picker = this.panel.querySelector( '.chatchi-emoji' );
		return !! picker && ! picker.hidden;
	};

	/**
	 * Opens or closes the emoji picker; toggles when no state is given.
	 */
	Widget.prototype.toggleEmoji = function ( open ) {
		var picker = this.panel.querySelector( '.chatchi-emoji' );
		var button = this.panel.querySelector( '.chatchi-tool--emoji' );

		if ( ! picker || ! button ) {
			return;
		}

		open = open === undefined ? picker.hidden : open;
		picker.hidden = ! open;
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

		if ( ! open ) {
			return;
		}

		var first = picker.querySelector( 'button' );
		if ( first && ! ( window.matchMedia && window.matchMedia( '(hover: none)' ).matches ) ) {
			first.focus( { preventScroll: true } );
		}
	};

	/**
	 * Inserts text at the cursor, as if typed, keeping the picker open for
	 * more. On touch screens the keyboard is not opened for it.
	 */
	Widget.prototype.insertText = function ( textarea, text ) {
		var start = textarea.selectionStart === null ? textarea.value.length : textarea.selectionStart;
		var end = textarea.selectionEnd === null ? start : textarea.selectionEnd;

		if ( textarea.setRangeText ) {
			textarea.setRangeText( text, start, end, 'end' );
		} else {
			textarea.value = textarea.value.slice( 0, start ) + text + textarea.value.slice( end );
		}

		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		if ( ! ( window.matchMedia && window.matchMedia( '(hover: none)' ).matches ) ) {
			textarea.focus( { preventScroll: true } );
		}
	};

	/* ------------------------------ start ------------------------------ */

	function start() {
		new Widget().mount();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
