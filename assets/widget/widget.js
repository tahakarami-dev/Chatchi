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
	var PAGE_SIZE = 30;

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
			draft: ''
		};
		this.lastReadMarked = 0;
		this.newIds = {};
		this.pollTimer = null;
		this.pollDelay = POLL_OPEN;
		this.pollCount = 0;
		this.booted = false;
		this.shellKey = null;
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
		}, [ icon( 'chat' ), this.badge ] );

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
			if ( event.key === 'Escape' && self.state.open ) {
				event.stopPropagation();
				self.close();
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
				self.markRead();
				self.schedulePoll( 0 );
			}
		} );

		this.render();
		this.boot();
	};

	/* ---------------------------- state flow --------------------------- */

	Widget.prototype.set = function ( patch ) {
		Object.assign( this.state, patch );
		this.render();
	};

	Widget.prototype.boot = function () {
		var self = this;

		return api.session().then( function ( session ) {
			self.booted = true;
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
		this.set( { open: true } );
		this.launcher.setAttribute( 'aria-expanded', 'true' );
		this.launcher.setAttribute( 'aria-label', S.closeChat );
		this.lockScroll( true );

		if ( this.state.view === 'error' ) {
			this.set( { view: 'loading' } );
			this.boot();
		}

		this.scrollToBottom();
		this.markRead();
		this.focusDefault();
		this.schedulePoll( 0 );
	};

	Widget.prototype.close = function () {
		this.set( { open: false, confirmingEnd: false } );
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
	};

	Widget.prototype.send = function ( text ) {
		var pending = { clientId: uuid(), body: text, failed: false };

		this.state.pending.push( pending );
		this.set( { draft: '' } );
		this.scrollToBottom();
		this.deliver( pending );
	};

	Widget.prototype.deliver = function ( pending ) {
		var self = this;

		pending.failed = false;
		this.render();

		this.ensureConversation().then( function ( conversation ) {
			return api.send( conversation.id, pending.body, pending.clientId );
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
			pending.error = error.message;
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
			this.startPromise = api.start( { name: visitor.name, email: visitor.email, phone: visitor.phone } ).then( function ( result ) {
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

		api.start( data ).then( function ( result ) {
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
			self.set( { submitting: false, formErrors: fields, formAlert: Object.keys( fields ).length ? '' : error.message } );
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
			if ( page.items.length ) {
				var atBottom = self.isAtBottom();
				self.merge( page.items );
				self.render();
				if ( atBottom ) {
					self.scrollToBottom();
				}
				self.markRead();
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

		if ( changed ) {
			this.state.conversation = session.conversation;
			this.set( { unread: unread } );
		}
	};

	/* ------------------------------ render ----------------------------- */

	Widget.prototype.isAtBottom = function () {
		var log = this.panel.querySelector( '.chatchi-log' );
		return ! log || log.scrollHeight - log.scrollTop - log.clientHeight < 40;
	};

	Widget.prototype.scrollToBottom = function () {
		var self = this;
		window.requestAnimationFrame( function () {
			var log = self.panel.querySelector( '.chatchi-log' );
			if ( log ) {
				log.scrollTop = log.scrollHeight;
			}
		} );
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

		this.newIds = {};
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
			agent ? this.renderAvatar( agent ) : null,
			h( 'div', { className: 'chatchi-header__text' }, [
				h( 'h2', { id: 'chatchi-title', className: 'chatchi-title', text: title } ),
				h( 'p', { className: 'chatchi-subtitle', text: agent ? S.support : S.subtitle } )
			] ),
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
			body.appendChild( this.renderLog() );
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

		Object.keys( specs ).forEach( function ( name ) {
			var field = fields[ name ];
			if ( ! field || ! field.enabled ) {
				return;
			}
			var spec = specs[ name ];
			var error = state.formErrors[ name ];
			var inputId = 'chatchi-field-' + name;

			form.appendChild( h( 'div', { className: 'chatchi-field' }, [
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

		if ( state.formAlert ) {
			form.appendChild( h( 'p', { className: 'chatchi-alert', role: 'alert', text: state.formAlert } ) );
		}

		form.appendChild( h( 'button', { type: 'submit', className: 'chatchi-button', disabled: state.submitting, text: state.submitting ? S.loading : S.start } ) );

		return form;
	};

	Widget.prototype.renderLog = function () {
		var self = this;
		var state = this.state;
		var log = h( 'div', { className: 'chatchi-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', tabindex: '0' } );

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

		return log;
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

		var bubble = h( 'div', { className: 'chatchi-bubble' } );
		bubble.appendChild( linkify( entry.body ) );
		stack.appendChild( bubble );

		if ( entry.failed ) {
			stack.appendChild( h( 'button', {
				type: 'button',
				className: 'chatchi-retry',
				text: S.sendFailed,
				onClick: function () {
					self.deliver( entry.ref );
					self.focusDefault();
				}
			} ) );
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
		var sendButton = h( 'button', { type: 'submit', className: 'chatchi-send', 'aria-label': S.send, disabled: ! state.draft.trim() }, [ icon( 'send', 'chatchi-flip' ) ] );
		var textarea = h( 'textarea', {
			className: 'chatchi-textarea',
			rows: '1',
			placeholder: S.placeholder,
			'aria-label': S.placeholder,
			maxlength: String( max ),
			value: state.draft
		} );

		function refresh() {
			var left = max - textarea.value.length;
			sendButton.disabled = ! textarea.value.trim();
			counter.hidden = left > 200;
			counter.textContent = format( S.charactersLeft, Math.max( 0, left ) );
			textarea.style.height = 'auto';
			textarea.style.height = Math.min( textarea.scrollHeight, 120 ) + 'px';
		}

		function submit() {
			var text = textarea.value.trim();
			if ( ! text ) {
				return;
			}
			textarea.value = '';
			self.state.draft = '';
			refresh();
			self.send( text );
		}

		textarea.addEventListener( 'input', function () {
			self.state.draft = textarea.value;
			refresh();
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
		}, [ textarea, sendButton ] );

		window.requestAnimationFrame( refresh );

		return h( 'div', { className: 'chatchi-footer' }, [ form, counter ] );
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
