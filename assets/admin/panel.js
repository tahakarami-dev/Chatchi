/**
 * ChatChi agent panel.
 *
 * A standalone single-page app served at wp-admin/admin.php?page=chatchi.
 * Build-free; uses the shared ChatChiCore (assets/shared/core.js) and the
 * chatchi/v1 agent REST API.
 *
 * Rendering is split by pane (list, conversation, details) and the message
 * log is re-rendered on its own, so a reply being typed is never disturbed
 * by incoming messages or list refreshes.
 *
 * Security rule: user-provided text is only ever inserted with textContent.
 */
( function () {
	'use strict';

	var config = window.ChatChiPanel;
	var Core = window.ChatChiCore;
	var root = document.getElementById( 'chatchi-app' );

	if ( ! config || ! Core || ! root ) {
		return;
	}

	var S = config.strings;
	var h = Core.h;
	var icon = Core.icon;
	var fmt = Core.createFormatter( config.locale, S );
	var client = Core.createApi( { restUrl: config.restUrl, nonce: config.nonce, networkError: S.networkError } );
	var request = client.request;

	var PER_PAGE = 20;
	var PAGE_SIZE = 30;
	var LIST_POLL = 10000;
	var CONV_POLL = 4000;
	var HIDDEN_POLL = 30000;
	var MAX_BACKOFF = 60000;
	var DETAIL_EVERY = 5;
	var GROUP_GAP_MS = 2 * 60 * 1000;
	var SEARCH_DELAY = 300;

	var api = {
		me: function () {
			return request( 'GET', '/agent/me' );
		},
		inbox: function ( query ) {
			return request( 'GET', '/agent/conversations', { query: query } );
		},
		conversation: function ( id ) {
			return request( 'GET', '/agent/conversations/' + id );
		},
		messages: function ( id, query ) {
			return request( 'GET', '/agent/conversations/' + id + '/messages', { query: query } );
		},
		send: function ( id, body, clientId ) {
			return request( 'POST', '/agent/conversations/' + id + '/messages', { body: { body: body, client_id: clientId } } );
		},
		read: function ( id, upTo ) {
			return request( 'POST', '/agent/conversations/' + id + '/read', { body: { up_to_id: upTo } } );
		},
		close: function ( id ) {
			return request( 'POST', '/agent/conversations/' + id + '/close' );
		},
		agents: function () {
			return request( 'GET', '/agent/agents' );
		},
		createAgent: function ( data ) {
			return request( 'POST', '/agent/agents', { body: data } );
		},
		updateAgent: function ( id, data ) {
			// POST is accepted too, for servers that block PATCH.
			return request( 'POST', '/agent/agents/' + id, { body: data } );
		},
		users: function ( search ) {
			return request( 'GET', '/agent/users', { query: { search: search } } );
		},
		settings: function () {
			return request( 'GET', '/agent/settings' );
		},
		saveSettings: function ( data ) {
			// POST is accepted too, for servers that block PATCH.
			return request( 'POST', '/agent/settings', { body: data } );
		},
		content: function ( search ) {
			return request( 'GET', '/agent/content', { query: { search: search } } );
		},
		uploadAvatar: function ( id, file ) {
			var form = new FormData();
			form.append( 'file', file );

			return fetch( client.endpoint( '/agent/agents/' + id + '/avatar' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json', 'X-WP-Nonce': config.nonce },
				body: form
			} ).then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( data ) {
					if ( response.ok ) {
						return data;
					}
					throw new Core.ApiError( response.status, data && data.code, data && data.message ? data.message : S.networkError, data && data.data );
				} );
			}, function () {
				throw new Core.ApiError( 0, 'network', S.networkError );
			} );
		}
	};

	var STATUS_LABELS = { 'new': S.statusNew, active: S.statusActive, closed: S.statusClosed };

	function visitorName( visitor ) {
		if ( visitor && visitor.name ) {
			return visitor.name;
		}
		return visitor ? fmt.format( S.visitorNumber, visitor.id ) : S.visitor;
	}

	function statusBadge( status ) {
		return h( 'span', { className: 'cc-badge cc-badge--' + status, text: STATUS_LABELS[ status ] || status } );
	}

	function avatar( name, size, url ) {
		var className = 'cc-avatar' + ( size ? ' cc-avatar--' + size : '' );
		if ( url ) {
			return h( 'span', { className: className }, [ h( 'img', { src: url, alt: '' } ) ] );
		}
		return h( 'span', { className: className, 'aria-hidden': 'true', text: Core.initials( name ) || '?' } );
	}

	function isTyping() {
		var el = document.activeElement;
		return el && ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' );
	}

	/* ------------------------------------------------------------------ *
	 * Panel
	 * ------------------------------------------------------------------ */

	function Panel() {
		var wide = window.matchMedia( '(min-width: 1280px)' ).matches;

		this.state = {
			agent: null,
			status: '',
			search: '',
			list: { items: [], total: 0, counts: { 'new': 0, active: 0, closed: 0 }, loading: true, error: '', loadingMore: false },
			selectedId: 0,
			conv: null,
			convLoading: false,
			convError: '',
			messages: [],
			pending: [],
			hasMore: false,
			loadingEarlier: false,
			confirmingClose: false,
			infoOpen: wide
		};

		this.drafts = {};
		this.newIds = {};
		this.lastReadMarked = 0;
		this.listTimer = null;
		this.convTimer = null;
		this.convTicks = 0;
		this.listDelay = LIST_POLL;
		this.convDelay = CONV_POLL;
		this.baseTitle = document.title;
		this.searchTimer = null;
		this.listRequest = 0;
	}

	Panel.prototype.mount = function () {
		var self = this;

		root.replaceChildren();
		root.setAttribute( 'dir', config.dir );

		this.rail = h( 'nav', { className: 'cc-rail', 'aria-label': S.appName } );
		this.listPane = h( 'section', { className: 'cc-list-pane', 'aria-label': S.conversations } );
		this.convPane = h( 'main', { className: 'cc-conv-pane' } );
		this.infoPane = h( 'aside', { className: 'cc-info-pane', 'aria-label': S.visitorInfo } );
		this.page = h( 'main', { className: 'cc-page' } );
		this.shell = h( 'div', { className: 'cc-shell' }, [ this.rail, this.listPane, this.convPane, this.infoPane, this.page ] );
		root.appendChild( this.shell );
		this.view = 'inbox';
		this.agentsView = config.canManageAgents ? new AgentsView( this ) : null;
		this.settingsView = config.canManageSettings ? new SettingsView( this ) : null;
		this.toast = h( 'div', { className: 'cc-toast', role: 'status', 'aria-live': 'polite', hidden: true } );
		root.appendChild( this.toast );

		this.buildListHead();
		this.renderRail();
		this.renderList();
		this.renderConversation( true );
		this.renderInfo();

		window.addEventListener( 'hashchange', function () {
			self.route();
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				self.markRead();
				self.scheduleList( 0 );
				self.scheduleConv( 0 );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				if ( self.state.confirmingClose ) {
					self.setConv( { confirmingClose: false } );
				} else if ( self.state.infoOpen && ! window.matchMedia( '(min-width: 1280px)' ).matches ) {
					self.toggleInfo( false );
				}
			} else if ( event.key === '/' && ! isTyping() ) {
				event.preventDefault();
				self.searchInput.focus();
			}
		} );

		api.me().then( function ( agent ) {
			self.state.agent = agent;
			self.renderRail();
		} ).catch( function () {} );

		this.loadList();
		this.route();
	};

	/* ------------------------------- rail ------------------------------ */

	Panel.prototype.renderRail = function () {
		var agent = this.state.agent;
		var hasUnread = this.state.list.items.some( function ( item ) {
			return item.unread_count > 0;
		} );

		var view = this.view;
		var railLink = function ( name, href, label, iconName ) {
			var current = view === name;
			return h( 'a', { className: 'cc-rail-item' + ( current ? ' is-active' : '' ), href: href, 'aria-current': current ? 'page' : null, title: label, 'aria-label': label }, [ icon( iconName ) ] );
		};
		var inbox = view === 'inbox';

		this.rail.replaceChildren(
			h( 'span', { className: 'cc-logo', title: S.appName }, [ icon( 'chat' ) ] ),
			h( 'a', { className: 'cc-rail-item' + ( inbox ? ' is-active' : '' ), href: '#/', 'aria-current': inbox ? 'page' : null, title: S.conversations, 'aria-label': S.conversations }, [
				icon( 'inbox' ),
				hasUnread ? h( 'span', { className: 'cc-rail-dot' } ) : null
			] ),
			this.agentsView ? railLink( 'agents', '#/agents', S.agents, 'users' ) : null,
			this.settingsView ? railLink( 'settings', '#/settings', S.settings, 'sliders' ) : null,
			h( 'span', { className: 'cc-rail-spacer' } ),
			agent ? h( 'span', { title: agent.name }, [ avatar( agent.name, 'rail', agent.avatar_url ) ] ) : null,
			h( 'a', { className: 'cc-rail-item', href: config.adminUrl, title: S.backToWp, 'aria-label': S.backToWp }, [ icon( 'exit', 'cc-flip' ) ] )
		);
	};

	/* ------------------------------- list ------------------------------ */

	Panel.prototype.buildListHead = function () {
		var self = this;

		this.searchInput = h( 'input', {
			type: 'search',
			placeholder: S.search,
			'aria-label': S.search,
			onInput: function () {
				window.clearTimeout( self.searchTimer );
				self.searchTimer = window.setTimeout( function () {
					self.state.search = self.searchInput.value.trim();
					self.loadList();
				}, SEARCH_DELAY );
			}
		} );

		this.tabs = h( 'div', { className: 'cc-tabs', role: 'tablist', 'aria-label': S.status } );
		this.listTitle = h( 'h1', { text: S.conversations } );
		this.listBody = h( 'ul', { className: 'cc-list', 'aria-live': 'polite', 'aria-busy': 'true' } );

		this.listPane.replaceChildren(
			h( 'div', { className: 'cc-list-head' }, [
				this.listTitle,
				h( 'div', { className: 'cc-search' }, [ icon( 'search' ), this.searchInput ] ),
				this.tabs
			] ),
			this.listBody
		);
	};

	Panel.prototype.renderTabs = function () {
		var self = this;
		var counts = this.state.list.counts;
		var total = counts[ 'new' ] + counts.active + counts.closed;
		var tabs = [
			{ status: '', label: S.all, count: total },
			{ status: 'new', label: S.statusNew, count: counts[ 'new' ] },
			{ status: 'active', label: S.statusActive, count: counts.active },
			{ status: 'closed', label: S.statusClosed, count: counts.closed }
		];

		this.tabs.replaceChildren.apply( this.tabs, tabs.map( function ( tab ) {
			return h( 'button', {
				type: 'button',
				className: 'cc-tab',
				role: 'tab',
				'aria-selected': self.state.status === tab.status ? 'true' : 'false',
				onClick: function () {
					if ( self.state.status !== tab.status ) {
						self.state.status = tab.status;
						self.loadList();
					}
				}
			}, [ tab.label, h( 'small', { text: fmt.number( tab.count ) } ) ] );
		} ) );
	};

	/**
	 * Loads the first page for the current filter, or refreshes what is shown.
	 */
	Panel.prototype.loadList = function ( refresh ) {
		var self = this;
		var list = this.state.list;
		var ticket = ++this.listRequest;
		var perPage = refresh ? Math.min( 100, Math.max( PER_PAGE, list.items.length ) ) : PER_PAGE;

		if ( ! refresh ) {
			list.loading = true;
			list.error = '';
			list.items = [];
			this.renderList();
		}

		return api.inbox( { status: this.state.status, search: this.state.search, page: 1, per_page: perPage } ).then( function ( page ) {
			if ( ticket !== self.listRequest ) {
				return;
			}
			list.items = page.items;
			list.total = page.total;
			list.counts = page.counts;
			list.loading = false;
			list.error = '';
			self.listDelay = LIST_POLL;
			self.renderList();
			self.syncSelected();
		} ).catch( function ( error ) {
			if ( ticket !== self.listRequest ) {
				return;
			}
			list.loading = false;
			if ( ! refresh ) {
				list.error = error.message || S.loadError;
				self.renderList();
			}
			self.listDelay = Math.min( MAX_BACKOFF, self.listDelay * 2 );
		} ).then( function () {
			if ( ticket === self.listRequest ) {
				self.scheduleList();
			}
		} );
	};

	Panel.prototype.loadMore = function () {
		var self = this;
		var list = this.state.list;
		var page = Math.floor( list.items.length / PER_PAGE ) + 1;

		list.loadingMore = true;
		this.renderList();

		api.inbox( { status: this.state.status, search: this.state.search, page: page, per_page: PER_PAGE } ).then( function ( result ) {
			var known = {};
			list.items.forEach( function ( item ) {
				known[ item.id ] = true;
			} );
			result.items.forEach( function ( item ) {
				if ( ! known[ item.id ] ) {
					list.items.push( item );
				}
			} );
			list.total = result.total;
			list.counts = result.counts;
		} ).catch( function () {} ).then( function () {
			list.loadingMore = false;
			self.renderList();
		} );
	};

	Panel.prototype.scheduleList = function ( delay ) {
		var self = this;
		window.clearTimeout( this.listTimer );

		if ( delay === undefined ) {
			delay = document.hidden ? HIDDEN_POLL : this.listDelay;
		}

		this.listTimer = window.setTimeout( function () {
			self.loadList( true );
		}, delay );
	};

	Panel.prototype.renderList = function () {
		var self = this;
		var list = this.state.list;
		var body = this.listBody;
		var unreadCount = list.items.filter( function ( item ) {
			return item.unread_count > 0;
		} ).length;

		document.title = ( unreadCount ? '(' + fmt.number( unreadCount ) + ') ' : '' ) + this.baseTitle;
		body.setAttribute( 'aria-busy', list.loading ? 'true' : 'false' );
		this.renderTabs();
		this.renderRail();

		if ( list.loading && ! list.items.length ) {
			var skeletons = [];
			for ( var i = 0; i < 6; i++ ) {
				skeletons.push( h( 'li', { className: 'cc-list-skeleton', 'aria-hidden': 'true' }, [
					h( 'span', { className: 'cc-skeleton' } ),
					h( 'div', {}, [ h( 'span', { className: 'cc-skeleton' } ), h( 'span', { className: 'cc-skeleton' } ) ] )
				] ) );
			}
			body.replaceChildren.apply( body, skeletons );
			return;
		}

		if ( list.error && ! list.items.length ) {
			body.replaceChildren( h( 'li', { className: 'cc-state', role: 'alert' }, [
				icon( 'alert' ),
				h( 'p', { text: list.error } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					text: S.retry,
					onClick: function () {
						self.loadList();
					}
				} )
			] ) );
			return;
		}

		if ( ! list.items.length ) {
			var message;
			if ( this.state.search ) {
				message = [ h( 'h2', { text: S.noResults.replace( '%s', this.state.search ) } ) ];
			} else if ( this.state.status ) {
				message = [ h( 'h2', { text: S.emptyFilter } ) ];
			} else {
				message = [ h( 'h2', { text: S.emptyInbox } ), h( 'p', { text: S.emptyInboxHint } ) ];
			}
			body.replaceChildren( h( 'li', { className: 'cc-state' }, [ icon( 'inbox' ) ].concat( message ) ) );
			return;
		}

		var items = list.items.map( function ( item ) {
			return h( 'li', {}, [ self.renderItem( item ) ] );
		} );

		if ( list.items.length < list.total ) {
			items.push( h( 'li', { className: 'cc-list-more' }, [
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					disabled: list.loadingMore,
					text: list.loadingMore ? S.loading : S.loadMore,
					onClick: function () {
						self.loadMore();
					}
				} )
			] ) );
		}

		body.replaceChildren.apply( body, items );
	};

	Panel.prototype.renderItem = function ( item ) {
		var name = visitorName( item.visitor );
		var last = item.last_message;
		var preview = last ? ( last.is_mine ? S.you + ': ' : '' ) + last.body.replace( /\s+/g, ' ' ) : S.noMessages;
		var classes = 'cc-item' + ( item.id === this.state.selectedId ? ' is-selected' : '' ) + ( item.unread_count > 0 ? ' is-unread' : '' );

		return h( 'a', {
			className: classes,
			href: '#/conversations/' + item.id,
			'aria-current': item.id === this.state.selectedId ? 'true' : null
		}, [
			avatar( name ),
			h( 'span', { className: 'cc-item__body' }, [
				h( 'span', { className: 'cc-item__top' }, [
					h( 'span', { className: 'cc-item__name', text: name } ),
					h( 'time', { className: 'cc-item__time', datetime: item.last_message_at, text: fmt.ago( new Date( item.last_message_at ) ) } )
				] ),
				h( 'span', { className: 'cc-item__bottom' }, [
					h( 'span', { className: 'cc-status-dot cc-status-dot--' + item.status, title: STATUS_LABELS[ item.status ] } ),
					h( 'span', { className: 'cc-item__preview', text: preview } ),
					item.unread_count > 0 ? h( 'span', { className: 'cc-count', 'aria-label': fmt.format( S.unread, item.unread_count ), text: fmt.number( item.unread_count ) } ) : null
				] )
			] )
		] );
	};

	/**
	 * Keeps the open conversation's list entry in sync with what the panel knows.
	 */
	Panel.prototype.syncSelected = function () {
		var conv = this.state.conv;
		if ( ! conv ) {
			return;
		}
		this.state.list.items.forEach( function ( item ) {
			if ( item.id === conv.id ) {
				item.status = conv.status;
				item.unread_count = 0;
			}
		} );
	};

	/* --------------------------- conversation -------------------------- */

	Panel.prototype.route = function () {
		var hash = window.location.hash;
		var settings = this.settingsView && /^#\/settings(\/[a-z]+)?$/.exec( hash );
		var page = null;

		if ( this.agentsView && hash === '#/agents' ) {
			page = 'agents';
		} else if ( settings ) {
			page = 'settings';
		}

		this.view = page || 'inbox';
		this.shell.classList.toggle( 'is-page', !! page );
		this.renderRail();

		if ( page === 'agents' ) {
			this.agentsView.show();
			return;
		}
		if ( page === 'settings' ) {
			this.settingsView.show( settings[ 1 ] ? settings[ 1 ].slice( 1 ) : '' );
			return;
		}

		var match = /^#\/conversations\/(\d+)$/.exec( window.location.hash );
		var id = match ? parseInt( match[ 1 ], 10 ) : 0;

		if ( id !== this.state.selectedId ) {
			this.select( id );
		}
	};

	/**
	 * Shows a short confirmation or error message at the bottom of the screen.
	 */
	Panel.prototype.notify = function ( message, isError ) {
		var toast = this.toast;

		toast.textContent = message;
		toast.classList.toggle( 'is-error', !! isError );
		toast.hidden = false;

		window.clearTimeout( this.toastTimer );
		this.toastTimer = window.setTimeout( function () {
			toast.hidden = true;
		}, 3500 );
	};

	Panel.prototype.select = function ( id ) {
		var self = this;
		var state = this.state;

		window.clearTimeout( this.convTimer );

		state.selectedId = id;
		state.conv = null;
		state.messages = [];
		state.pending = [];
		state.hasMore = false;
		state.convError = '';
		state.confirmingClose = false;
		state.convLoading = !! id;
		this.lastReadMarked = 0;
		this.convTicks = 0;

		this.shell.classList.toggle( 'is-viewing', !! id );
		this.renderList();
		this.renderConversation( true );
		this.renderInfo();

		if ( ! id ) {
			return;
		}

		Promise.all( [ api.conversation( id ), api.messages( id, { limit: PAGE_SIZE } ) ] ).then( function ( results ) {
			if ( state.selectedId !== id ) {
				return;
			}
			state.conv = results[ 0 ];
			state.messages = results[ 1 ].items;
			state.hasMore = results[ 1 ].has_more;
			state.convLoading = false;
			self.renderConversation( true );
			self.renderInfo();
			self.scrollToBottom();
			self.markRead();
			self.focusComposer();
			self.scheduleConv();
		} ).catch( function ( error ) {
			if ( state.selectedId !== id ) {
				return;
			}
			state.convLoading = false;
			state.convError = error.message;
			self.renderConversation( true );
			self.renderInfo();
		} );
	};

	Panel.prototype.setConv = function ( patch ) {
		Object.assign( this.state, patch );
		this.renderConversation();
	};

	Panel.prototype.toggleInfo = function ( open ) {
		this.state.infoOpen = open === undefined ? ! this.state.infoOpen : open;
		this.renderConversation();
		this.renderInfo();
	};

	Panel.prototype.lastId = function () {
		var messages = this.state.messages;
		return messages.length ? messages[ messages.length - 1 ].id : 0;
	};

	Panel.prototype.merge = function ( items ) {
		var self = this;
		var known = {};

		this.state.messages.forEach( function ( m ) {
			known[ m.id ] = true;
		} );

		items.forEach( function ( m ) {
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

	Panel.prototype.scheduleConv = function ( delay ) {
		var self = this;
		window.clearTimeout( this.convTimer );

		if ( ! this.state.conv ) {
			return;
		}

		if ( delay === undefined ) {
			delay = document.hidden ? HIDDEN_POLL : this.convDelay;
		}

		this.convTimer = window.setTimeout( function () {
			self.pollConversation();
		}, delay );
	};

	Panel.prototype.pollConversation = function () {
		var self = this;
		var id = this.state.selectedId;

		if ( ! this.state.conv ) {
			return;
		}

		this.convTicks++;
		var withDetail = this.convTicks % DETAIL_EVERY === 0;

		this.pollMessages( id ).then( function () {
			return withDetail ? api.conversation( id ) : null;
		} ).then( function ( detail ) {
			if ( self.state.selectedId !== id ) {
				return;
			}
			if ( detail ) {
				self.applyDetail( detail );
			}
			self.convDelay = CONV_POLL;
			self.scheduleConv();
		} ).catch( function () {
			if ( self.state.selectedId !== id ) {
				return;
			}
			self.convDelay = Math.min( MAX_BACKOFF, self.convDelay * 2 );
			self.scheduleConv();
		} );
	};

	Panel.prototype.pollMessages = function ( id ) {
		var self = this;

		return api.messages( id, { after_id: this.lastId(), limit: 50 } ).then( function ( page ) {
			if ( self.state.selectedId !== id ) {
				return;
			}
			if ( page.items.length ) {
				var atBottom = self.isAtBottom();
				self.merge( page.items );
				self.renderLog();
				if ( atBottom ) {
					self.scrollToBottom();
				}
				self.markRead();
			}
			if ( page.has_more ) {
				return self.pollMessages( id );
			}
		} );
	};

	Panel.prototype.applyDetail = function ( detail ) {
		var before = JSON.stringify( [ this.state.conv.status, this.state.conv.visitor, this.state.conv.agent ] );
		this.state.conv = detail;

		if ( JSON.stringify( [ detail.status, detail.visitor, detail.agent ] ) !== before ) {
			this.renderConversation();
			this.renderInfo();
			this.syncSelected();
			this.renderList();
		}
	};

	Panel.prototype.markRead = function () {
		var self = this;
		var conv = this.state.conv;
		var lastId = this.lastId();

		if ( ! conv || document.hidden || lastId <= this.lastReadMarked ) {
			return;
		}

		var hasUnread = this.state.messages.some( function ( m ) {
			return ! m.is_mine && m.sender.type === 'visitor' && ! m.read_at && m.id > self.lastReadMarked;
		} );

		this.lastReadMarked = lastId;

		if ( hasUnread ) {
			api.read( conv.id, lastId ).then( function () {
				self.syncSelected();
				self.renderList();
			} ).catch( function () {
				self.lastReadMarked = 0;
			} );
		}
	};

	Panel.prototype.loadEarlier = function () {
		var self = this;
		var first = this.state.messages[ 0 ];
		var id = this.state.selectedId;

		if ( ! first || this.state.loadingEarlier ) {
			return;
		}

		var log = this.convPane.querySelector( '.cc-log' );
		var fromBottom = log ? log.scrollHeight - log.scrollTop : 0;

		this.state.loadingEarlier = true;
		this.renderLog();

		api.messages( id, { before_id: first.id, limit: PAGE_SIZE } ).then( function ( page ) {
			if ( self.state.selectedId !== id ) {
				return;
			}
			self.state.messages = page.items.concat( self.state.messages );
			self.state.hasMore = page.has_more;
		} ).catch( function () {} ).then( function () {
			self.state.loadingEarlier = false;
			self.renderLog();
			var newLog = self.convPane.querySelector( '.cc-log' );
			if ( newLog ) {
				newLog.scrollTop = newLog.scrollHeight - fromBottom;
			}
		} );
	};

	Panel.prototype.send = function ( text ) {
		var pending = { clientId: Core.uuid(), body: text, failed: false, convId: this.state.selectedId };

		this.state.pending.push( pending );
		this.drafts[ pending.convId ] = '';
		this.renderLog();
		this.scrollToBottom();
		this.deliver( pending );
	};

	Panel.prototype.deliver = function ( pending ) {
		var self = this;

		pending.failed = false;
		this.renderLog();

		api.send( pending.convId, pending.body, pending.clientId ).then( function ( message ) {
			if ( self.state.selectedId !== pending.convId ) {
				return;
			}
			self.merge( [ message ] );
			self.renderLog();
			self.scrollToBottom();

			// The first reply activates and assigns the conversation.
			if ( self.state.conv && self.state.conv.status === 'new' ) {
				api.conversation( pending.convId ).then( function ( detail ) {
					if ( self.state.selectedId === pending.convId ) {
						self.applyDetail( detail );
					}
				} ).catch( function () {} );
			}

			self.loadList( true );
		} ).catch( function ( error ) {
			if ( self.state.selectedId !== pending.convId ) {
				return;
			}
			if ( error.code === 'chatchi_conversation_closed' ) {
				self.state.pending = self.state.pending.filter( function ( p ) {
					return p !== pending;
				} );
				self.drafts[ pending.convId ] = pending.body;
				api.conversation( pending.convId ).then( function ( detail ) {
					self.applyDetail( detail );
				} ).catch( function () {} );
				return;
			}
			pending.failed = true;
			self.renderLog();
		} );
	};

	Panel.prototype.closeConversation = function () {
		var self = this;
		var id = this.state.selectedId;

		api.close( id ).then( function ( conv ) {
			if ( self.state.selectedId !== id ) {
				return;
			}
			self.state.confirmingClose = false;
			self.applyDetail( conv );
			self.renderConversation();
			self.loadList( true );
		} ).catch( function ( error ) {
			self.setConv( { confirmingClose: false } );
			window.alert( error.message );
		} );
	};

	/* ------------------------------ render ----------------------------- */

	Panel.prototype.isAtBottom = function () {
		var log = this.convPane.querySelector( '.cc-log' );
		return ! log || log.scrollHeight - log.scrollTop - log.clientHeight < 40;
	};

	Panel.prototype.scrollToBottom = function () {
		var self = this;
		window.requestAnimationFrame( function () {
			var log = self.convPane.querySelector( '.cc-log' );
			if ( log ) {
				log.scrollTop = log.scrollHeight;
			}
		} );
	};

	Panel.prototype.focusComposer = function () {
		var self = this;
		if ( window.matchMedia( '(max-width: 767px)' ).matches ) {
			return; // Do not pop up the keyboard on phones.
		}
		window.setTimeout( function () {
			var textarea = self.convPane.querySelector( 'textarea' );
			if ( textarea ) {
				textarea.focus( { preventScroll: true } );
			}
		}, 30 );
	};

	/**
	 * Rebuilds the conversation pane when its structure changes, otherwise
	 * only the message log.
	 */
	Panel.prototype.renderConversation = function ( force ) {
		var state = this.state;
		var conv = state.conv;
		var key = JSON.stringify( [
			state.selectedId,
			state.convLoading,
			state.convError,
			state.confirmingClose,
			state.infoOpen,
			conv ? [ conv.status, conv.visitor, conv.agent ] : null
		] );

		this.shell.classList.toggle( 'is-info-hidden', ! state.infoOpen || ! state.selectedId );

		if ( ! force && key === this.convKey ) {
			this.renderLog();
			return;
		}

		this.convKey = key;

		var textarea = this.convPane.querySelector( 'textarea' );
		var hadFocus = textarea && document.activeElement === textarea;

		this.convPane.replaceChildren.apply( this.convPane, this.buildConversation() );

		var newTextarea = this.convPane.querySelector( 'textarea' );
		if ( hadFocus && newTextarea ) {
			newTextarea.focus( { preventScroll: true } );
		}

		this.newIds = {};
	};

	Panel.prototype.buildConversation = function () {
		var self = this;
		var state = this.state;
		var conv = state.conv;

		if ( ! state.selectedId ) {
			return [ h( 'div', { className: 'cc-state' }, [ icon( 'chat' ), h( 'h2', { text: S.selectConversation } ), h( 'p', { text: S.selectHint } ) ] ) ];
		}

		var back = h( 'a', { className: 'cc-icon-button cc-back', href: '#/', 'aria-label': S.back, title: S.back }, [ icon( 'back', 'cc-flip' ) ] );

		if ( state.convLoading ) {
			return [
				h( 'header', { className: 'cc-conv-head' }, [ back, h( 'span', { className: 'cc-skeleton', style: 'width:36px;height:36px;border-radius:50%' } ), h( 'span', { className: 'cc-skeleton', style: 'width:160px;height:14px' } ) ] ),
				h( 'div', { className: 'cc-log', 'aria-busy': 'true' } )
			];
		}

		if ( state.convError || ! conv ) {
			return [
				h( 'header', { className: 'cc-conv-head' }, [ back ] ),
				h( 'div', { className: 'cc-state', role: 'alert' }, [ icon( 'alert' ), h( 'p', { text: state.convError || S.loadError } ) ] )
			];
		}

		var name = visitorName( conv.visitor );
		var subtitle = [ conv.visitor && conv.visitor.email, conv.visitor && conv.visitor.phone ].filter( Boolean ).join( ' · ' );
		var nodes = [
			h( 'header', { className: 'cc-conv-head' }, [
				back,
				avatar( name ),
				h( 'div', { className: 'cc-conv-head__text' }, [
					h( 'h2', { text: name } ),
					subtitle ? h( 'p', { dir: 'auto', text: subtitle } ) : null
				] ),
				statusBadge( conv.status ),
				conv.status !== 'closed' ? h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					text: S.closeConversation,
					onClick: function () {
						self.setConv( { confirmingClose: true } );
					}
				} ) : null,
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button' + ( state.infoOpen ? ' is-active' : '' ),
					'aria-label': S.details,
					title: S.details,
					'aria-pressed': state.infoOpen ? 'true' : 'false',
					onClick: function () {
						self.toggleInfo();
					}
				}, [ icon( 'info' ) ] )
			] )
		];

		if ( state.confirmingClose ) {
			nodes.push( h( 'div', { className: 'cc-confirm', role: 'alertdialog', 'aria-label': S.closeConfirm }, [
				h( 'p', { text: S.closeConfirm } ),
				h( 'div', {}, [
					h( 'button', {
						type: 'button',
						className: 'cc-button cc-button--secondary',
						text: S.cancel,
						onClick: function () {
							self.setConv( { confirmingClose: false } );
						}
					} ),
					h( 'button', {
						type: 'button',
						className: 'cc-button cc-button--danger',
						text: S.closeYes,
						onClick: function () {
							self.closeConversation();
						}
					} )
				] )
			] ) );
		}

		nodes.push( this.buildLog() );
		nodes.push( conv.status === 'closed' ? h( 'div', { className: 'cc-closed-notice', role: 'status', text: S.closedNotice } ) : this.buildComposer() );

		return nodes;
	};

	Panel.prototype.renderLog = function () {
		var log = this.convPane.querySelector( '.cc-log' );

		if ( ! log || ! this.state.conv ) {
			return;
		}

		var scrollTop = log.scrollTop;
		var fresh = this.buildLog();

		log.replaceWith( fresh );
		fresh.scrollTop = scrollTop;
		this.newIds = {};
	};

	Panel.prototype.buildLog = function () {
		var self = this;
		var state = this.state;
		var inner = h( 'div', { className: 'cc-log-inner' } );
		var log = h( 'div', { className: 'cc-log', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions', tabindex: '0' }, [ inner ] );

		if ( state.hasMore ) {
			inner.appendChild( h( 'button', {
				type: 'button',
				className: 'cc-earlier',
				disabled: state.loadingEarlier,
				text: state.loadingEarlier ? S.loading : S.earlier,
				onClick: function () {
					self.loadEarlier();
				}
			} ) );
		}

		var entries = state.messages.map( function ( m ) {
			return { key: m.id, mine: m.is_mine, type: m.sender.type, sender: m.sender, body: m.body, date: new Date( m.created_at ), read: !! m.read_at, isNew: !! self.newIds[ m.id ] };
		} ).concat( state.pending.map( function ( p ) {
			return { key: p.clientId, mine: true, type: 'agent', sender: null, body: p.body, date: new Date(), pending: ! p.failed, failed: p.failed, ref: p, isNew: true };
		} ) );

		if ( ! entries.length ) {
			inner.appendChild( h( 'div', { className: 'cc-state' }, [ h( 'p', { text: S.noMessages } ) ] ) );
			return log;
		}

		var visitor = state.conv ? visitorName( state.conv.visitor ) : S.visitor;
		var lastDay = null;

		entries.forEach( function ( entry, i ) {
			var prev = entries[ i - 1 ];
			var next = entries[ i + 1 ];
			var day = fmt.dayKey( entry.date );

			if ( day !== lastDay ) {
				inner.appendChild( h( 'div', { className: 'cc-day', text: fmt.dayLabel( entry.date ) } ) );
				lastDay = day;
			}

			var groupStart = ! prev || ! self.sameGroup( prev, entry );
			var groupEnd = ! next || ! self.sameGroup( entry, next );

			inner.appendChild( self.buildMessage( entry, groupStart, groupEnd, visitor ) );
		} );

		return log;
	};

	Panel.prototype.sameGroup = function ( a, b ) {
		return a.mine === b.mine &&
			a.type === b.type &&
			( a.sender && b.sender ? a.sender.name === b.sender.name : true ) &&
			fmt.dayKey( a.date ) === fmt.dayKey( b.date ) &&
			Math.abs( b.date - a.date ) < GROUP_GAP_MS;
	};

	Panel.prototype.buildMessage = function ( entry, groupStart, groupEnd, visitor ) {
		var self = this;

		if ( entry.type === 'system' ) {
			return h( 'div', { className: 'cc-day', text: entry.body } );
		}

		var classes = [ 'cc-row', entry.mine ? 'cc-row--mine' : 'cc-row--theirs' ];
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

		var senderName = entry.type === 'visitor' ? visitor : ( entry.sender && entry.sender.name ) || S.appName;
		var stack = h( 'div', { className: 'cc-stack' } );

		if ( ! entry.mine && groupStart ) {
			stack.appendChild( h( 'span', { className: 'cc-sender', text: senderName } ) );
		}

		var bubble = h( 'div', { className: 'cc-bubble' } );
		bubble.appendChild( Core.linkify( entry.body ) );
		stack.appendChild( bubble );

		if ( entry.failed ) {
			stack.appendChild( h( 'button', {
				type: 'button',
				className: 'cc-retry',
				text: S.sendFailed,
				onClick: function () {
					self.deliver( entry.ref );
				}
			} ) );
		} else if ( groupEnd ) {
			var meta = [ h( 'time', { text: fmt.time( entry.date ) } ) ];
			var metaClass = 'cc-meta';
			if ( entry.mine ) {
				if ( entry.pending ) {
					meta.push( icon( 'clock' ), h( 'span', { className: 'cc-sr', text: S.sending } ) );
				} else if ( entry.read ) {
					metaClass += ' is-read';
					meta.push( icon( 'checks' ), h( 'span', { className: 'cc-sr', text: S.read } ) );
				} else {
					meta.push( icon( 'check' ), h( 'span', { className: 'cc-sr', text: S.sent } ) );
				}
			}
			stack.appendChild( h( 'div', { className: metaClass }, meta ) );
		}

		var row = h( 'div', { className: classes.join( ' ' ) } );

		if ( ! entry.mine ) {
			var face = avatar( senderName, 'sm', entry.sender && entry.sender.avatar_url );
			if ( ! groupEnd ) {
				face.classList.add( 'cc-avatar--spacer' );
			}
			row.appendChild( face );
		}

		row.appendChild( stack );

		return row;
	};

	Panel.prototype.buildComposer = function () {
		var self = this;
		var id = this.state.selectedId;
		var send = h( 'button', { type: 'submit', className: 'cc-send', 'aria-label': S.send, title: S.send, disabled: ! ( this.drafts[ id ] || '' ).trim() }, [ icon( 'send', 'cc-flip' ) ] );
		var textarea = h( 'textarea', { rows: '1', placeholder: S.placeholder, 'aria-label': S.placeholder, value: this.drafts[ id ] || '' } );

		function refresh() {
			send.disabled = ! textarea.value.trim();
			textarea.style.height = 'auto';
			textarea.style.height = Math.min( textarea.scrollHeight, 200 ) + 'px';
		}

		function submit() {
			var text = textarea.value.trim();
			if ( ! text ) {
				return;
			}
			textarea.value = '';
			refresh();
			self.send( text );
		}

		textarea.addEventListener( 'input', function () {
			self.drafts[ id ] = textarea.value;
			refresh();
		} );

		textarea.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				submit();
			}
		} );

		window.requestAnimationFrame( refresh );

		return h( 'div', { className: 'cc-composer' }, [
			h( 'form', {
				onSubmit: function ( event ) {
					event.preventDefault();
					submit();
				}
			}, [ textarea, send ] )
		] );
	};

	/* ------------------------------ details ---------------------------- */

	Panel.prototype.renderInfo = function () {
		var self = this;
		var conv = this.state.conv;

		if ( ! conv || ! this.state.infoOpen ) {
			this.infoPane.replaceChildren();
			return;
		}

		var visitor = conv.visitor || {};
		var name = visitorName( conv.visitor );

		function row( iconName, label, value ) {
			return h( 'li', { className: 'cc-info-row' }, [
				icon( iconName ),
				h( 'div', {}, [ h( 'span', { className: 'cc-info-label', text: label } ), h( 'div', { className: 'cc-info-value' }, [ value ] ) ] )
			] );
		}

		function muted( text ) {
			return h( 'span', { className: 'cc-muted', text: text } );
		}

		var email = visitor.email ? h( 'a', { href: 'mailto:' + visitor.email, dir: 'ltr', text: visitor.email } ) : muted( S.notProvided );
		var phone = visitor.phone ? h( 'a', { href: 'tel:' + visitor.phone.replace( /[^\d+]/g, '' ), dir: 'ltr', text: visitor.phone } ) : muted( S.notProvided );
		var page = /^https?:\/\//i.test( conv.page_url || '' ) ? h( 'a', { href: conv.page_url, target: '_blank', rel: 'noopener noreferrer', dir: 'ltr', text: decodeSafe( conv.page_url ) } ) : muted( S.notProvided );

		var rows = [
			row( 'mail', S.email, email ),
			row( 'phone', S.phone, phone ),
			row( 'link', S.startPage, page ),
			row( 'clock', S.startedOn, document.createTextNode( fmt.dateTime( new Date( conv.created_at ) ) ) ),
			row( 'clock', S.lastSeen, visitor.last_seen_at ? document.createTextNode( fmt.ago( new Date( visitor.last_seen_at ) ) ) : muted( S.notProvided ) ),
			row( 'user', S.assignedTo, conv.agent ? document.createTextNode( conv.agent.name ) : muted( S.unassigned ) )
		];

		if ( visitor.user_id ) {
			rows.push( row( 'user', S.wpUser, h( 'a', { href: config.userEditUrl + '?user_id=' + encodeURIComponent( visitor.user_id ), text: S.viewProfile } ) ) );
		}

		this.infoPane.replaceChildren(
			h( 'div', { className: 'cc-info-head' }, [
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button cc-info-close',
					'aria-label': S.back,
					onClick: function () {
						self.toggleInfo( false );
					}
				}, [ icon( 'close' ) ] ),
				avatar( name, 'lg' ),
				h( 'h2', { text: name } ),
				statusBadge( conv.status )
			] ),
			h( 'ul', { className: 'cc-info-list' }, rows )
		);
	};

	/**
	 * Shows percent-encoded (e.g. Persian) URLs readably; falls back to the raw URL.
	 */
	function decodeSafe( url ) {
		try {
			return decodeURI( url );
		} catch ( e ) {
			return url;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Agents screen (site administrators only)
	 * ------------------------------------------------------------------ */

	/**
	 * Lists agents and lets administrators add WordPress users as agents,
	 * rename them, set their picture and switch them on or off.
	 *
	 * Inputs are built once per open form and only result lists are
	 * re-rendered, so typing (including Persian IME input) is not disturbed.
	 */
	function AgentsView( panel ) {
		this.panel = panel;
		this.el = panel.page;
		this.loaded = false;
		this.state = {
			loading: true,
			error: '',
			agents: [],
			adding: false,
			editingId: 0,
			busyId: 0
		};
		this.searchTimer = null;
		this.searchTicket = 0;
	}

	AgentsView.prototype.show = function () {
		if ( ! this.loaded ) {
			this.loaded = true;
			this.load();
		}
		this.render();
	};

	AgentsView.prototype.load = function () {
		var self = this;

		this.state.loading = true;
		this.state.error = '';
		this.render();

		api.agents().then( function ( agents ) {
			self.state.agents = agents;
			self.state.loading = false;
			self.render();
		} ).catch( function ( error ) {
			self.state.loading = false;
			self.state.error = error.message || S.agentsError;
			self.render();
		} );
	};

	AgentsView.prototype.replaceAgent = function ( updated ) {
		this.state.agents = this.state.agents.map( function ( agent ) {
			return agent.id === updated.id ? updated : agent;
		} );

		// Keep the rail avatar in sync when admins edit themselves.
		if ( updated.is_me && this.panel.state.agent ) {
			this.panel.state.agent.name = updated.name;
			this.panel.state.agent.avatar_url = updated.avatar_url;
			this.panel.renderRail();
		}
	};

	AgentsView.prototype.render = function () {
		var self = this;
		var state = this.state;

		// Responses can arrive after the admin moved on to another screen.
		if ( this.panel.view !== 'agents' ) {
			return;
		}

		var header = h( 'header', { className: 'cc-page-head' }, [
			h( 'div', {}, [
				h( 'h1', { text: S.agents } ),
				h( 'p', { text: S.agentsIntro } )
			] ),
			state.adding ? null : h( 'button', {
				type: 'button',
				className: 'cc-button',
				onClick: function () {
					self.openAdd();
				}
			}, [ icon( 'plus' ), S.addAgent ] )
		] );

		var body;

		if ( state.loading ) {
			body = h( 'div', { className: 'cc-agent-list', 'aria-busy': 'true' }, [ 1, 2, 3 ].map( function () {
				return h( 'div', { className: 'cc-agent cc-agent--skeleton' }, [
					h( 'span', { className: 'cc-skeleton', style: 'width:48px;height:48px;border-radius:50%' } ),
					h( 'span', { className: 'cc-skeleton', style: 'width:40%;height:14px' } )
				] );
			} ) );
		} else if ( state.error ) {
			body = h( 'div', { className: 'cc-state', role: 'alert' }, [
				icon( 'alert' ),
				h( 'p', { text: state.error } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					text: S.retry,
					onClick: function () {
						self.load();
					}
				} )
			] );
		} else if ( ! state.agents.length && ! state.adding ) {
			body = h( 'div', { className: 'cc-state' }, [
				icon( 'users' ),
				h( 'h2', { text: S.noAgents } ),
				h( 'p', { text: S.noAgentsHint } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button',
					onClick: function () {
						self.openAdd();
					}
				}, [ icon( 'plus' ), S.addAgent ] )
			] );
		} else {
			body = h( 'div', { className: 'cc-agent-list' }, state.agents.map( function ( agent ) {
				return agent.id === state.editingId ? self.buildEditor( agent ) : self.buildCard( agent );
			} ) );
		}

		this.el.replaceChildren( h( 'div', { className: 'cc-page-inner' }, [
			header,
			state.adding ? this.buildAddForm() : null,
			body
		] ) );
	};

	AgentsView.prototype.buildCard = function ( agent ) {
		var self = this;
		var busy = this.state.busyId === agent.id;
		var user = agent.user;

		return h( 'article', { className: 'cc-agent' + ( agent.is_active ? '' : ' is-inactive' ) }, [
			avatar( agent.name, 'lg', agent.avatar_url ),
			h( 'div', { className: 'cc-agent__text' }, [
				h( 'h2', {}, [
					agent.name,
					agent.is_me ? h( 'span', { className: 'cc-tag', text: S.you } ) : null
				] ),
				user ? h( 'p', { dir: 'ltr', text: user.login + ' · ' + user.email } ) : null
			] ),
			h( 'button', {
				type: 'button',
				className: 'cc-switch',
				role: 'switch',
				'aria-checked': agent.is_active ? 'true' : 'false',
				'aria-label': ( agent.is_active ? S.active : S.inactive ) + ' — ' + agent.name,
				title: agent.is_me ? S.cannotDeactivateMe : ( agent.is_active ? S.active : S.inactive ),
				disabled: busy || ( agent.is_me && agent.is_active ),
				onClick: function () {
					self.toggle( agent );
				}
			}, [ h( 'span', { className: 'cc-switch__track' } ), h( 'span', { className: 'cc-switch__label', text: agent.is_active ? S.active : S.inactive } ) ] ),
			h( 'button', {
				type: 'button',
				className: 'cc-button cc-button--secondary',
				text: S.edit,
				disabled: busy,
				onClick: function () {
					self.state.editingId = agent.id;
					self.render();
					var input = self.el.querySelector( '#cc-agent-name' );
					if ( input ) {
						input.focus();
					}
				}
			} )
		] );
	};

	AgentsView.prototype.toggle = function ( agent ) {
		var self = this;
		var next = ! agent.is_active;

		this.state.busyId = agent.id;
		this.render();

		api.updateAgent( agent.id, { is_active: next } ).then( function ( updated ) {
			self.replaceAgent( updated );
			self.panel.notify( S.saved );
		} ).catch( function ( error ) {
			self.panel.notify( error.message, true );
		} ).then( function () {
			self.state.busyId = 0;
			self.render();
		} );
	};

	AgentsView.prototype.buildEditor = function ( agent ) {
		var self = this;
		var error = h( 'p', { className: 'cc-field-error', role: 'alert', hidden: true } );
		var nameInput = h( 'input', { id: 'cc-agent-name', className: 'cc-input', type: 'text', maxlength: '100', value: agent.name, required: true } );
		var preview = avatar( agent.name, 'xl', agent.avatar_url );
		var file = h( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/gif,image/webp', className: 'cc-sr', id: 'cc-agent-file' } );
		var saveButton = h( 'button', { type: 'submit', className: 'cc-button', text: S.save } );

		function showError( message ) {
			error.textContent = message;
			error.hidden = ! message;
		}

		file.addEventListener( 'change', function () {
			var chosen = file.files && file.files[ 0 ];
			if ( ! chosen ) {
				return;
			}
			if ( chosen.size > config.maxAvatarBytes ) {
				showError( S.pictureTooLarge.replace( '%s', fmt.number( Math.round( config.maxAvatarBytes / 1048576 ) ) + ' MB' ) );
				file.value = '';
				return;
			}
			showError( '' );
			uploadLabel.textContent = S.uploading;
			api.uploadAvatar( agent.id, chosen ).then( function ( updated ) {
				self.replaceAgent( updated );
				preview.replaceWith( avatar( updated.name, 'xl', updated.avatar_url ) );
				agent.avatar_url = updated.avatar_url;
				agent.avatar_id = updated.avatar_id;
				self.panel.notify( S.saved );
			} ).catch( function ( err ) {
				showError( err.message );
			} ).then( function () {
				uploadLabel.textContent = S.uploadPicture;
				file.value = '';
				preview = self.el.querySelector( '.cc-agent-editor .cc-avatar' ) || preview;
			} );
		} );

		var uploadLabel = h( 'label', { className: 'cc-button cc-button--secondary', for: 'cc-agent-file', text: S.uploadPicture } );

		var form = h( 'form', {
			className: 'cc-agent cc-agent-editor',
			onSubmit: function ( event ) {
				event.preventDefault();
				saveButton.disabled = true;
				saveButton.textContent = S.saving;
				showError( '' );
				api.updateAgent( agent.id, { display_name: nameInput.value } ).then( function ( updated ) {
					self.replaceAgent( updated );
					self.state.editingId = 0;
					self.render();
					self.panel.notify( S.saved );
				} ).catch( function ( err ) {
					saveButton.disabled = false;
					saveButton.textContent = S.save;
					showError( err.message );
					nameInput.focus();
				} );
			}
		}, [
			preview,
			h( 'div', { className: 'cc-agent-editor__fields' }, [
				h( 'label', { className: 'cc-label', for: 'cc-agent-name', text: S.displayName } ),
				nameInput,
				h( 'span', { className: 'cc-label', text: S.picture } ),
				h( 'div', { className: 'cc-inline' }, [
					config.canUpload ? uploadLabel : null,
					config.canUpload ? file : null,
					agent.avatar_id ? h( 'button', {
						type: 'button',
						className: 'cc-button cc-button--secondary',
						text: S.removePicture,
						onClick: function () {
							api.updateAgent( agent.id, { avatar_id: 0 } ).then( function ( updated ) {
								self.replaceAgent( updated );
								self.render();
								self.panel.notify( S.saved );
							} ).catch( function ( err ) {
								showError( err.message );
							} );
						}
					} ) : null
				] ),
				h( 'p', { className: 'cc-hint', text: S.pictureHint } ),
				error,
				h( 'div', { className: 'cc-inline' }, [
					saveButton,
					h( 'button', {
						type: 'button',
						className: 'cc-button cc-button--secondary',
						text: S.cancel,
						onClick: function () {
							self.state.editingId = 0;
							self.render();
						}
					} )
				] )
			] )
		] );

		return form;
	};

	AgentsView.prototype.openAdd = function () {
		this.state.adding = true;
		this.state.editingId = 0;
		this.selectedUser = null;
		this.render();

		var search = this.el.querySelector( '#cc-user-search' );
		if ( search ) {
			search.focus();
		}
		this.searchUsers( '' );
	};

	AgentsView.prototype.buildAddForm = function () {
		var self = this;

		this.results = h( 'ul', { className: 'cc-user-results', 'aria-live': 'polite' } );
		this.addError = h( 'p', { className: 'cc-field-error', role: 'alert', hidden: true } );

		var search = h( 'input', {
			id: 'cc-user-search',
			className: 'cc-input',
			type: 'search',
			placeholder: S.findUser,
			'aria-label': S.findUser,
			onInput: function () {
				window.clearTimeout( self.searchTimer );
				self.searchTimer = window.setTimeout( function () {
					self.searchUsers( search.value.trim() );
				}, SEARCH_DELAY );
			}
		} );

		this.confirmArea = h( 'div', { className: 'cc-add-confirm' } );

		return h( 'section', { className: 'cc-card cc-add', 'aria-label': S.addAgent }, [
			h( 'div', { className: 'cc-add__head' }, [
				h( 'h2', { text: S.addAgent } ),
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button',
					'aria-label': S.cancel,
					onClick: function () {
						self.state.adding = false;
						self.render();
					}
				}, [ icon( 'close' ) ] )
			] ),
			search,
			this.results,
			this.confirmArea,
			this.addError
		] );
	};

	AgentsView.prototype.searchUsers = function ( term ) {
		var self = this;
		var ticket = ++this.searchTicket;

		this.results.replaceChildren( h( 'li', { className: 'cc-hint', text: S.loading } ) );

		api.users( term ).then( function ( users ) {
			if ( ticket !== self.searchTicket ) {
				return;
			}
			if ( ! users.length ) {
				self.results.replaceChildren( h( 'li', { className: 'cc-hint', text: S.noUsers } ) );
				return;
			}
			self.results.replaceChildren.apply( self.results, users.map( function ( user ) {
				return h( 'li', {}, [
					h( 'button', {
						type: 'button',
						className: 'cc-user' + ( self.selectedUser && self.selectedUser.id === user.id ? ' is-selected' : '' ),
						'data-user-id': user.id,
						'aria-pressed': self.selectedUser && self.selectedUser.id === user.id ? 'true' : 'false',
						onClick: function () {
							self.selectUser( user );
						}
					}, [
						avatar( user.display_name ),
						h( 'span', {}, [
							h( 'strong', { text: user.display_name } ),
							h( 'small', { dir: 'ltr', text: user.login + ' · ' + user.email } )
						] )
					] )
				] );
			} ) );
		} ).catch( function ( error ) {
			if ( ticket === self.searchTicket ) {
				self.results.replaceChildren( h( 'li', { className: 'cc-field-error', text: error.message } ) );
			}
		} );
	};

	AgentsView.prototype.selectUser = function ( user ) {
		var self = this;
		var nameInput = h( 'input', { id: 'cc-new-agent-name', className: 'cc-input', type: 'text', maxlength: '100', value: user.display_name } );
		var submit = h( 'button', { type: 'submit', className: 'cc-button', text: S.create } );

		this.selectedUser = user;
		this.results.querySelectorAll( '.cc-user' ).forEach( function ( button ) {
			var selected = button.getAttribute( 'data-user-id' ) === String( user.id );
			button.classList.toggle( 'is-selected', selected );
			button.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
		} );

		this.confirmArea.replaceChildren( h( 'form', {
			className: 'cc-inline cc-inline--wrap',
			onSubmit: function ( event ) {
				event.preventDefault();
				submit.disabled = true;
				submit.textContent = S.saving;
				self.addError.hidden = true;
				api.createAgent( { user_id: user.id, display_name: nameInput.value } ).then( function ( agent ) {
					self.state.agents.push( agent );
					self.state.adding = false;
					self.render();
					self.panel.notify( S.saved );
				} ).catch( function ( error ) {
					submit.disabled = false;
					submit.textContent = S.create;
					self.addError.textContent = error.message;
					self.addError.hidden = false;
				} );
			}
		}, [
			h( 'label', { className: 'cc-label', for: 'cc-new-agent-name', text: S.displayName } ),
			nameInput,
			submit
		] ) );

		nameInput.focus();
		nameInput.select();
	};

	/* ------------------------------------------------------------------ *
	 * Settings screen (site administrators only)
	 * ------------------------------------------------------------------ */

	/**
	 * Welcome message, where the widget appears, and the shortcode.
	 *
	 * The form is built once per load or save; typing only updates the
	 * draft, the character counter and the search results, so input
	 * (including Persian IME composition) is never interrupted.
	 */
	function SettingsView( panel ) {
		this.panel = panel;
		this.el = panel.page;
		this.loaded = false;
		this.state = { loading: true, error: '' };
		this.section = SETTINGS_SECTIONS[ 0 ].id;
		this.data = null;
		this.draft = {};
		this.items = [];
		this.searchTimer = null;
		this.searchTicket = 0;
	}

	/**
	 * Settings sections: each has its own screen and saves only its keys, so
	 * errors and unsaved changes stay where they belong.
	 */
	var SETTINGS_SECTIONS = [
		{ id: 'general', icon: 'power', keys: [ 'widget_enabled', 'visitor_cookie_days', 'delete_data_on_uninstall' ] },
		{ id: 'appearance', icon: 'palette', keys: [ 'primary_color', 'widget_position', 'widget_title', 'widget_subtitle' ] },
		{ id: 'form', icon: 'clipboard', keys: [ 'form_name', 'form_email', 'form_phone', 'form_intro' ] },
		{ id: 'display', icon: 'eye', keys: [ 'welcome_enabled', 'welcome_message', 'display_mode', 'display_targets', 'display_ids' ] },
		{ id: 'limits', icon: 'gauge', keys: [ 'spam_protection', 'limit_messages_visitor', 'limit_messages_ip', 'limit_starts_visitor', 'limit_starts_ip', 'message_max_length' ] },
		{ id: 'security', icon: 'shield', keys: [ 'proxy_mode', 'proxy_header', 'trusted_proxies' ] }
	];

	var NUMBER_SETTINGS = [ 'visitor_cookie_days', 'message_max_length', 'limit_messages_visitor', 'limit_messages_ip', 'limit_starts_visitor', 'limit_starts_ip' ];

	function settingsSection( id ) {
		return SETTINGS_SECTIONS.filter( function ( section ) {
			return section.id === id;
		} )[ 0 ] || null;
	}

	SettingsView.prototype.show = function ( section ) {
		this.section = settingsSection( section ) ? section : SETTINGS_SECTIONS[ 0 ].id;

		if ( ! this.loaded ) {
			this.loaded = true;
			this.load();
			return;
		}
		this.render();
	};

	SettingsView.prototype.load = function () {
		var self = this;

		this.state = { loading: true, error: '' };
		this.render();

		api.settings().then( function ( data ) {
			self.apply( data );
			self.state.loading = false;
			self.render();
		} ).catch( function ( error ) {
			self.state = { loading: false, error: error.message || S.settingsError };
			self.render();
		} );
	};

	/**
	 * Takes a server response; resets the drafts of the given sections (all
	 * when none are given) and keeps unsaved edits in the others.
	 */
	SettingsView.prototype.apply = function ( data, sections ) {
		var self = this;

		this.data = data;
		( sections || SETTINGS_SECTIONS.map( function ( s ) {
			return s.id;
		} ) ).forEach( function ( id ) {
			self.resetDraft( id );
		} );
	};

	SettingsView.prototype.resetDraft = function ( id ) {
		var draft = this.draft;
		var saved = this.data.settings;

		settingsSection( id ).keys.forEach( function ( key ) {
			if ( key === 'display_ids' ) {
				return;
			}
			var value = saved[ key ];
			draft[ key ] = Array.isArray( value ) ? value.slice() : value;
		} );

		if ( id === 'display' ) {
			// Items that were deleted since they were chosen are dropped on save.
			this.items = this.data.items.slice();
		}
		if ( id === 'security' ) {
			draft.trusted_proxies = saved.trusted_proxies.join( '\n' );
		}
	};

	/**
	 * Returns the values a section would save, from the draft.
	 */
	SettingsView.prototype.payload = function ( id ) {
		var draft = this.draft;
		var payload = {};

		settingsSection( id ).keys.forEach( function ( key ) {
			payload[ key ] = draft[ key ];
		} );

		if ( id === 'display' ) {
			payload.display_ids = this.items.map( function ( item ) {
				return item.id;
			} );
		}
		if ( id === 'security' ) {
			payload.proxy_header = draft.proxy_header.trim();
			payload.trusted_proxies = draft.trusted_proxies.split( /[\s,]+/ ).filter( function ( line ) {
				return line !== '';
			} );
		}
		if ( id === 'appearance' ) {
			payload.primary_color = draft.primary_color.trim();
		}

		// Whole numbers go out as numbers; anything else is sent as typed so
		// the server can say what is wrong with it.
		NUMBER_SETTINGS.forEach( function ( key ) {
			if ( key in payload ) {
				var text = String( payload[ key ] ).trim();
				payload[ key ] = /^\d+$/.test( text ) ? parseInt( text, 10 ) : text;
			}
		} );

		return payload;
	};

	/**
	 * Whether a section has changes that are not saved yet.
	 */
	SettingsView.prototype.isDirty = function ( id ) {
		var self = this;
		var saved = this.data.settings;
		var payload = this.payload( id );

		return Object.keys( payload ).some( function ( key ) {
			var current = payload[ key ];
			var stored = saved[ key ];

			if ( key === 'primary_color' ) {
				current = String( current ).toUpperCase().replace( /^#?/, current ? '#' : '' );
			}
			if ( key === 'display_targets' ) {
				current = current.slice().sort();
				stored = stored.slice().sort();
			}
			if ( key === 'display_ids' ) {
				// Chosen items deleted since are dropped anyway; not a change.
				stored = self.data.items.map( function ( item ) {
					return item.id;
				} );
			}
			return JSON.stringify( current ) !== JSON.stringify( stored );
		} );
	};

	SettingsView.prototype.render = function () {
		var self = this;

		if ( this.panel.view !== 'settings' ) {
			return;
		}

		var header = h( 'header', { className: 'cc-page-head' }, [
			h( 'div', {}, [
				h( 'h1', { text: S.settings } ),
				h( 'p', { text: S.settingsIntro } )
			] )
		] );

		var body;

		if ( this.state.loading ) {
			body = h( 'div', { className: 'cc-settings', 'aria-busy': 'true' }, [ 1, 2 ].map( function () {
				return h( 'div', { className: 'cc-card' }, [
					h( 'span', { className: 'cc-skeleton', style: 'display:block;width:30%;height:16px;margin-bottom:16px' } ),
					h( 'span', { className: 'cc-skeleton', style: 'display:block;width:100%;height:80px' } )
				] );
			} ) );
		} else if ( this.state.error ) {
			body = h( 'div', { className: 'cc-state', role: 'alert' }, [
				icon( 'alert' ),
				h( 'p', { text: this.state.error } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					text: S.retry,
					onClick: function () {
						self.load();
					}
				} )
			] );
		} else {
			body = h( 'div', { className: 'cc-settings-layout' }, [ this.buildNav(), this.buildForm() ] );
		}

		this.el.replaceChildren( h( 'div', { className: 'cc-page-inner cc-page-inner--wide' }, [ header, body ] ) );

		// The picker is part of the form just built; fill its suggestions.
		if ( ! this.state.loading && ! this.state.error && this.section === 'display' && this.draft.display_mode !== 'all' ) {
			this.searchContent( '' );
		}
	};

	SettingsView.prototype.buildNav = function () {
		var self = this;
		var labels = {
			general: S.sectionGeneral,
			appearance: S.sectionAppearance,
			form: S.sectionForm,
			display: S.sectionDisplay,
			limits: S.sectionLimits,
			security: S.sectionSecurity
		};

		this.navLinks = {};

		return h( 'nav', { className: 'cc-subnav', 'aria-label': S.settings }, SETTINGS_SECTIONS.map( function ( section ) {
			var current = section.id === self.section;
			var dirty = self.isDirty( section.id );
			var link = h( 'a', {
				className: 'cc-subnav__item' + ( current ? ' is-active' : '' ) + ( dirty ? ' is-dirty' : '' ),
				href: '#/settings/' + section.id,
				'aria-current': current ? 'page' : null
			}, [
				icon( section.icon ),
				h( 'span', { text: labels[ section.id ] } ),
				h( 'span', { className: 'cc-subnav__dot', title: S.unsaved, 'aria-label': S.unsaved, hidden: ! dirty } )
			] );

			self.navLinks[ section.id ] = link;
			return link;
		} ) );
	};

	/**
	 * Updates the "unsaved changes" dots after any edit.
	 */
	SettingsView.prototype.syncNav = function () {
		var self = this;

		SETTINGS_SECTIONS.forEach( function ( section ) {
			var link = self.navLinks && self.navLinks[ section.id ];
			if ( link ) {
				var dirty = self.isDirty( section.id );
				link.classList.toggle( 'is-dirty', dirty );
				link.querySelector( '.cc-subnav__dot' ).hidden = ! dirty;
			}
		} );
	};

	SettingsView.prototype.buildForm = function () {
		var self = this;
		var children;

		this.errors = {};
		this.saveButton = h( 'button', { type: 'submit', className: 'cc-button', text: S.save } );

		if ( this.section === 'general' ) {
			children = [ this.buildGeneral() ];
		} else if ( this.section === 'appearance' ) {
			children = [ this.buildAppearance() ];
		} else if ( this.section === 'form' ) {
			children = [ this.buildStartForm() ];
		} else if ( this.section === 'limits' ) {
			children = [ this.buildLimits() ];
		} else if ( this.section === 'security' ) {
			children = [ this.buildProxy() ];
		} else {
			children = [ this.buildWelcome(), this.buildDisplay(), this.buildShortcode() ];
		}

		var form = h( 'form', {
			className: 'cc-settings',
			noValidate: true,
			'data-section': this.section,
			onSubmit: function ( event ) {
				event.preventDefault();
				self.save();
			}
		}, children.concat( [ h( 'div', { className: 'cc-settings__actions' }, [ this.saveButton ] ) ] ) );

		// Any edit may change which sections have unsaved changes.
		[ 'input', 'change', 'click' ].forEach( function ( type ) {
			form.addEventListener( type, function () {
				window.setTimeout( function () {
					self.syncNav();
				}, 0 );
			} );
		} );

		return form;
	};

	/* --------------------------- brand colors -------------------------- */

	var DEFAULT_TEXT_ON_LIGHT = '#1A1A2E';
	var COLOR_PRESETS = [ '#5B5BD6', '#2563EB', '#0D9488', '#16A34A', '#EA580C', '#DC2626', '#DB2777', '#1F2937' ];

	/**
	 * Returns #RRGGBB for "#abc", "abc", "#aabbcc" or "aabbcc", else null.
	 */
	function normalizeHex( value ) {
		var match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec( String( value ).trim() );
		if ( ! match ) {
			return null;
		}
		var hex = match[ 1 ];
		if ( hex.length === 3 ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		return '#' + hex.toUpperCase();
	}

	function hexToRgb( hex ) {
		return [ 1, 3, 5 ].map( function ( i ) {
			return parseInt( hex.substr( i, 2 ), 16 );
		} );
	}

	function rgbToHex( rgb ) {
		return '#' + rgb.map( function ( c ) {
			return ( '0' + c.toString( 16 ) ).slice( -2 );
		} ).join( '' ).toUpperCase();
	}

	function mixRgb( rgb, target, weight ) {
		return rgb.map( function ( c, i ) {
			return Math.round( c + ( target[ i ] - c ) * weight );
		} );
	}

	function luminance( rgb ) {
		var linear = rgb.map( function ( c ) {
			c /= 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * linear[ 0 ] + 0.7152 * linear[ 1 ] + 0.0722 * linear[ 2 ];
	}

	function contrastRatio( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	/**
	 * Same derivation as ColorPalette::tokens() on the server.
	 */
	function colorTokens( hex ) {
		var rgb = hexToRgb( hex );
		var white = [ 255, 255, 255 ];

		return {
			primary: hex,
			primaryHover: rgbToHex( mixRgb( rgb, [ 0, 0, 0 ], 0.12 ) ),
			primarySoft: rgbToHex( mixRgb( rgb, white, 0.9 ) ),
			primaryContrast: contrastRatio( rgb, white ) >= 4.5 ? '#FFFFFF' : DEFAULT_TEXT_ON_LIGHT
		};
	}

	function setColorVars( el, hex ) {
		var tokens = colorTokens( hex );

		el.style.setProperty( '--chatchi-primary', tokens.primary );
		el.style.setProperty( '--chatchi-primary-hover', tokens.primaryHover );
		el.style.setProperty( '--chatchi-primary-soft', tokens.primarySoft );
		el.style.setProperty( '--chatchi-primary-contrast', tokens.primaryContrast );
	}

	/**
	 * Recolors the panel itself right after the brand color is saved.
	 */
	function applyBrandColor( hex ) {
		var color = normalizeHex( hex );
		if ( color ) {
			setColorVars( document.documentElement, color );
		}
	}

	/* ---------------------------- appearance --------------------------- */

	SettingsView.prototype.buildAppearance = function () {
		var self = this;
		var draft = this.draft;
		var defaults = this.data.defaults;

		// Color: presets, the native picker and a hex field, kept in sync.
		var hexInput = h( 'input', {
			id: 'cc-color',
			className: 'cc-input cc-input--code',
			type: 'text',
			dir: 'ltr',
			maxlength: '7',
			spellcheck: 'false',
			autocomplete: 'off',
			placeholder: defaults.primary_color,
			'aria-describedby': 'cc-color-hint'
		} );
		hexInput.value = draft.primary_color;

		var picker = h( 'input', { id: 'cc-color-picker', className: 'cc-color-picker', type: 'color', 'aria-label': S.pickColor } );
		var swatches = h( 'div', { className: 'cc-swatches', role: 'group', 'aria-label': S.brandColor }, COLOR_PRESETS.map( function ( color ) {
			var swatch = h( 'button', {
				type: 'button',
				className: 'cc-swatch',
				'data-color': color,
				'aria-label': S.presetColor.replace( '%s', color ),
				title: color,
				onClick: function () {
					setColor( color === defaults.primary_color ? '' : color );
				}
			} );
			swatch.style.setProperty( '--swatch', color );
			return swatch;
		} ) );
		var reset = h( 'button', {
			type: 'button',
			className: 'cc-link-button',
			text: S.resetColor,
			onClick: function () {
				setColor( '' );
			}
		} );
		var contrastWarning = h( 'p', { className: 'cc-warning', role: 'status', text: S.colorLowContrast } );
		var overridden = h( 'p', { className: 'cc-hint', role: 'status' } );

		function effectiveColor() {
			return normalizeHex( draft.primary_color ) || defaults.primary_color;
		}

		function syncColor() {
			var color = effectiveColor();
			var valid = draft.primary_color.trim() === '' || !! normalizeHex( draft.primary_color );

			picker.value = color.toLowerCase();
			reset.hidden = draft.primary_color.trim() === '';
			swatches.querySelectorAll( '.cc-swatch' ).forEach( function ( swatch ) {
				swatch.setAttribute( 'aria-pressed', swatch.getAttribute( 'data-color' ) === color ? 'true' : 'false' );
			} );
			contrastWarning.hidden = ! valid || contrastRatio( hexToRgb( color ), [ 255, 255, 255 ] ) >= 3;

			var saved = normalizeHex( self.data.settings.primary_color ) || defaults.primary_color;
			var used = self.data.effective.primary_color;
			overridden.hidden = used === saved;
			overridden.textContent = S.colorOverridden.replace( '%s', used );

			self.updatePreview();
		}

		function setColor( value ) {
			draft.primary_color = value;
			hexInput.value = value;
			self.clearError( 'primary_color' );
			syncColor();
		}

		hexInput.addEventListener( 'input', function () {
			draft.primary_color = hexInput.value;
			self.clearError( 'primary_color' );
			syncColor();
		} );
		picker.addEventListener( 'input', function () {
			setColor( picker.value.toUpperCase() === defaults.primary_color ? '' : picker.value.toUpperCase() );
		} );

		// Position.
		var positions = h( 'div', { className: 'cc-positions', role: 'radiogroup', 'aria-labelledby': 'cc-position-label' }, [
			[ 'left', S.positionLeft ],
			[ 'right', S.positionRight ]
		].map( function ( option ) {
			var id = 'cc-position-' + option[ 0 ];
			var input = h( 'input', { id: id, type: 'radio', name: 'cc-position', value: option[ 0 ], checked: draft.widget_position === option[ 0 ] } );

			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					draft.widget_position = option[ 0 ];
					self.updatePreview();
				}
			} );

			return h( 'label', { className: 'cc-position cc-position--' + option[ 0 ], for: id }, [
				input,
				h( 'span', { className: 'cc-position__screen', 'aria-hidden': 'true' }, [ h( 'span', { className: 'cc-position__dot' } ) ] ),
				h( 'span', { text: option[ 1 ] } )
			] );
		} ) );

		// Title and subtitle.
		function textField( key, id, label ) {
			var input = h( 'input', {
				id: id,
				className: 'cc-input',
				type: 'text',
				placeholder: defaults[ key ],
				'aria-describedby': 'cc-text-default'
			} );
			input.value = draft[ key ];
			input.addEventListener( 'input', function () {
				draft[ key ] = input.value;
				self.clearError( key );
				self.updatePreview();
			} );

			return [ h( 'label', { className: 'cc-label', for: id, text: label } ), input, self.fieldError( key ) ];
		}

		var fields = h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-appearance-title' }, [
			h( 'div', {}, [
				h( 'h2', { id: 'cc-appearance-title', text: S.appearanceTitle } ),
				h( 'p', { className: 'cc-hint', text: S.appearanceIntro } )
			] ),
			h( 'span', { className: 'cc-label', id: 'cc-color-label', text: S.brandColor } ),
			swatches,
			h( 'div', { className: 'cc-inline' }, [
				picker,
				h( 'label', { className: 'cc-sr', for: 'cc-color', text: S.colorCode } ),
				hexInput,
				reset
			] ),
			h( 'p', { id: 'cc-color-hint', className: 'cc-hint', text: S.colorHint } ),
			this.fieldError( 'primary_color' ),
			contrastWarning,
			overridden,
			h( 'span', { className: 'cc-label', id: 'cc-position-label', text: S.position } ),
			positions,
			this.fieldError( 'widget_position' )
		].concat(
			textField( 'widget_title', 'cc-widget-title', S.widgetTitle ),
			textField( 'widget_subtitle', 'cc-widget-subtitle', S.widgetSubtitle ),
			[ h( 'p', { id: 'cc-text-default', className: 'cc-hint', text: S.textDefault } ) ]
		) );

		this.preview = this.buildPreview();

		var layout = h( 'div', { className: 'cc-appearance' }, [ fields, this.preview.root ] );

		syncColor();

		return layout;
	};

	/**
	 * A small mock of the widget that follows the unsaved appearance draft.
	 */
	SettingsView.prototype.buildPreview = function () {
		var title = h( 'strong', { className: 'cc-preview__title' } );
		var subtitle = h( 'span', { className: 'cc-preview__subtitle' } );
		var welcome = h( 'div', { className: 'cc-preview__bubble cc-preview__bubble--theirs' } );
		var frame = h( 'div', { className: 'cc-preview__frame', dir: config.dir } , [
			h( 'div', { className: 'cc-preview__panel' }, [
				h( 'div', { className: 'cc-preview__header' }, [ title, subtitle ] ),
				h( 'div', { className: 'cc-preview__body' }, [
					welcome,
					h( 'div', { className: 'cc-preview__bubble cc-preview__bubble--mine', text: S.previewMessage } )
				] ),
				h( 'div', { className: 'cc-preview__composer' }, [ h( 'span', { text: S.previewPlaceholder } ), h( 'span', { className: 'cc-preview__send' }, [ icon( 'send', 'cc-flip' ) ] ) ] )
			] ),
			h( 'span', { className: 'cc-preview__launcher' }, [ icon( 'chat' ) ] )
		] );

		return {
			root: h( 'aside', { className: 'cc-card cc-preview', 'aria-label': S.preview }, [
				h( 'h2', { text: S.preview } ),
				frame
			] ),
			frame: frame,
			title: title,
			subtitle: subtitle,
			welcome: welcome
		};
	};

	SettingsView.prototype.updatePreview = function () {
		var preview = this.preview;
		var draft = this.draft;
		var defaults = this.data.defaults;

		if ( ! preview ) {
			return;
		}

		setColorVars( preview.frame, normalizeHex( draft.primary_color ) || defaults.primary_color );
		preview.frame.classList.toggle( 'is-right', draft.widget_position === 'right' );
		preview.title.textContent = draft.widget_title.trim() || defaults.widget_title;
		preview.subtitle.textContent = draft.widget_subtitle.trim() || defaults.widget_subtitle;

		var welcomeOn = this.data.settings.welcome_enabled;
		preview.welcome.hidden = ! welcomeOn;
		preview.welcome.textContent = this.data.settings.welcome_message || defaults.welcome_message;
	};

	/* ------------------------------ general ---------------------------- */

	/**
	 * A labelled whole-number field with an optional unit after it.
	 */
	SettingsView.prototype.numberField = function ( key, label, unit, hint ) {
		var self = this;
		var draft = this.draft;
		var range = this.data.ranges[ key ];
		var id = 'cc-' + key.replace( /_/g, '-' );
		var input = h( 'input', {
			id: id,
			className: 'cc-input cc-input--number',
			type: 'number',
			inputmode: 'numeric',
			min: String( range[ 0 ] ),
			max: String( range[ 1 ] ),
			step: '1',
			dir: 'ltr',
			'aria-describedby': hint ? id + '-hint' : null
		} );
		input.value = String( draft[ key ] );
		input.addEventListener( 'input', function () {
			draft[ key ] = input.value;
			self.clearError( key );
		} );

		return h( 'div', { className: 'cc-number-field' }, [
			h( 'label', { className: 'cc-label', for: id, text: label } ),
			h( 'div', { className: 'cc-inline' }, [ input, unit ? h( 'span', { className: 'cc-unit', text: unit } ) : null ] ),
			hint ? h( 'p', { id: id + '-hint', className: 'cc-hint', text: hint } ) : null,
			this.fieldError( key )
		] );
	};

	SettingsView.prototype.buildGeneral = function () {
		var draft = this.draft;
		var offWarning = h( 'p', { className: 'cc-warning', role: 'status', text: S.widgetOffWarning } );
		var deleteWarning = h( 'p', { className: 'cc-warning cc-warning--danger', role: 'status', text: S.deleteDataWarning } );

		offWarning.hidden = draft.widget_enabled;
		deleteWarning.hidden = ! draft.delete_data_on_uninstall;

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-widget-on-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-widget-on-title', text: S.widgetOnTitle } ),
						h( 'p', { className: 'cc-hint', text: S.widgetOnIntro } )
					] ),
					this.buildSwitch( 'cc-widget-enabled', S.widgetOn, draft.widget_enabled, function ( on ) {
						draft.widget_enabled = on;
						offWarning.hidden = on;
					} )
				] ),
				offWarning,
				this.fieldError( 'widget_enabled' )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-visitors-title' }, [
				h( 'h2', { id: 'cc-visitors-title', text: S.visitorsTitle } ),
				this.numberField( 'visitor_cookie_days', S.visitorDays, S.days, S.visitorDaysHint )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-data-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-data-title', text: S.dataTitle } ),
						h( 'p', { className: 'cc-hint', text: S.dataIntro } )
					] ),
					this.buildSwitch( 'cc-delete-data', S.deleteData, draft.delete_data_on_uninstall, function ( on ) {
						draft.delete_data_on_uninstall = on;
						deleteWarning.hidden = ! on;
					} )
				] ),
				deleteWarning,
				this.fieldError( 'delete_data_on_uninstall' )
			] )
		] );
	};

	/* ----------------------------- start form -------------------------- */

	SettingsView.prototype.buildStartForm = function () {
		var self = this;
		var draft = this.draft;
		var modes = [ [ 'off', S.fieldOff ], [ 'optional', S.fieldOptional ], [ 'required', S.fieldRequired ] ];
		var fields = [ [ 'form_name', S.name ], [ 'form_email', S.email ], [ 'form_phone', S.phone ] ];
		var noForm = h( 'p', { className: 'cc-hint cc-note', role: 'status', text: S.formOffNote } );

		function syncNoForm() {
			noForm.hidden = fields.some( function ( field ) {
				return draft[ field[ 0 ] ] !== 'off';
			} );
		}

		var rows = fields.map( function ( field ) {
			var key = field[ 0 ];

			return h( 'div', { className: 'cc-field-row' }, [
				h( 'span', { className: 'cc-field-row__name', id: 'cc-' + key + '-label', text: field[ 1 ] } ),
				h( 'div', { className: 'cc-segmented', role: 'radiogroup', 'aria-labelledby': 'cc-' + key + '-label' }, modes.map( function ( mode ) {
					var id = 'cc-' + key.replace( '_', '-' ) + '-' + mode[ 0 ];
					var input = h( 'input', { id: id, type: 'radio', name: 'cc-' + key, value: mode[ 0 ], checked: draft[ key ] === mode[ 0 ] } );

					input.addEventListener( 'change', function () {
						if ( input.checked ) {
							draft[ key ] = mode[ 0 ];
							self.clearError( key );
							syncNoForm();
						}
					} );

					return h( 'label', { className: 'cc-segmented__option', for: id }, [ input, h( 'span', { text: mode[ 1 ] } ) ] );
				} ) ),
				self.fieldError( key )
			] );
		} );

		var intro = h( 'input', {
			id: 'cc-form-intro',
			className: 'cc-input',
			type: 'text',
			placeholder: this.data.defaults.form_intro,
			'aria-describedby': 'cc-form-intro-hint'
		} );
		intro.value = draft.form_intro;
		intro.addEventListener( 'input', function () {
			draft.form_intro = intro.value;
			self.clearError( 'form_intro' );
		} );

		syncNoForm();

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-form-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-form-title', text: S.formTitle } ),
					h( 'p', { className: 'cc-hint', text: S.formIntroText } )
				] ),
				h( 'div', { className: 'cc-field-rows' }, rows ),
				noForm
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-form-intro-title' }, [
				h( 'h2', { id: 'cc-form-intro-title', text: S.formIntroTitle } ),
				h( 'label', { className: 'cc-sr', for: 'cc-form-intro', text: S.formIntroTitle } ),
				intro,
				h( 'p', { id: 'cc-form-intro-hint', className: 'cc-hint', text: S.textDefault } ),
				this.fieldError( 'form_intro' )
			] )
		] );
	};

	/* --------------------------- spam and limits ----------------------- */

	SettingsView.prototype.buildLimits = function () {
		var draft = this.draft;

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-spam-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-spam-title', text: S.spamTitle } ),
						h( 'p', { className: 'cc-hint', text: S.spamIntro } )
					] ),
					this.buildSwitch( 'cc-spam-protection', S.spamOn, draft.spam_protection, function ( on ) {
						draft.spam_protection = on;
					} )
				] ),
				this.fieldError( 'spam_protection' )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-limits-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-limits-title', text: S.limitsTitle } ),
					h( 'p', { className: 'cc-hint', text: S.limitsIntro } )
				] ),
				h( 'div', { className: 'cc-number-grid' }, [
					this.numberField( 'limit_messages_visitor', S.limitMessagesVisitor, S.perMinute ),
					this.numberField( 'limit_messages_ip', S.limitMessagesIp, S.perMinute ),
					this.numberField( 'limit_starts_visitor', S.limitStartsVisitor, S.perHour ),
					this.numberField( 'limit_starts_ip', S.limitStartsIp, S.perTenMinutes )
				] )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-length-title' }, [
				h( 'h2', { id: 'cc-length-title', text: S.messagesTitle } ),
				this.numberField( 'message_max_length', S.messageMaxLength, S.characters )
			] )
		] );
	};

	SettingsView.prototype.fieldError = function ( key ) {
		this.errors[ key ] = h( 'p', { className: 'cc-field-error', role: 'alert', hidden: true } );
		return this.errors[ key ];
	};

	SettingsView.prototype.clearError = function ( key ) {
		if ( this.errors[ key ] ) {
			this.errors[ key ].hidden = true;
		}
	};

	SettingsView.prototype.buildWelcome = function () {
		var self = this;
		var draft = this.draft;
		var limit = this.data.limits.welcome_message;

		var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
		var textarea = h( 'textarea', {
			id: 'cc-welcome-message',
			className: 'cc-input cc-textarea',
			rows: '3',
			placeholder: this.data.defaults.welcome_message,
			disabled: ! draft.welcome_enabled,
			'aria-describedby': 'cc-welcome-hint'
		} );
		textarea.value = draft.welcome_message;

		// Counts characters the way the server does (an emoji is one), so
		// no maxlength attribute, which counts UTF-16 units.
		function count() {
			var used = Array.from( textarea.value ).length;
			counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limit );
			counter.classList.toggle( 'is-over', used > limit );
		}

		textarea.addEventListener( 'input', function () {
			draft.welcome_message = textarea.value;
			count();
			self.clearError( 'welcome_message' );
		} );
		count();

		var toggle = this.buildSwitch( 'cc-welcome-enabled', S.welcomeEnabled, draft.welcome_enabled, function ( on ) {
			draft.welcome_enabled = on;
			textarea.disabled = ! on;
		} );

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-welcome-title' }, [
			h( 'div', { className: 'cc-settings__head' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-welcome-title', text: S.welcomeTitle } ),
					h( 'p', { className: 'cc-hint', text: S.welcomeIntro } )
				] ),
				toggle
			] ),
			h( 'label', { className: 'cc-label', for: 'cc-welcome-message', text: S.welcomeText } ),
			textarea,
			h( 'div', { className: 'cc-settings__row' }, [
				h( 'p', { id: 'cc-welcome-hint', className: 'cc-hint', text: S.welcomeDefault } ),
				counter
			] ),
			this.fieldError( 'welcome_message' ),
			this.fieldError( 'welcome_enabled' )
		] );
	};

	SettingsView.prototype.buildSwitch = function ( id, label, on, onChange ) {
		var button = h( 'button', {
			id: id,
			type: 'button',
			className: 'cc-switch',
			role: 'switch',
			'aria-checked': on ? 'true' : 'false',
			'aria-label': label
		}, [ h( 'span', { className: 'cc-switch__track' } ), h( 'span', { className: 'cc-switch__label', text: label } ) ] );

		button.addEventListener( 'click', function () {
			on = ! on;
			button.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			onChange( on );
		} );

		return button;
	};

	SettingsView.prototype.buildDisplay = function () {
		var self = this;
		var draft = this.draft;
		var modes = [
			[ 'all', S.displayAll ],
			[ 'include', S.displayInclude ],
			[ 'exclude', S.displayExclude ]
		];

		this.pickerLegend = h( 'span', { className: 'cc-label' } );
		this.picker = h( 'div', { className: 'cc-picker' }, [
			this.pickerLegend,
			this.buildTargets(),
			h( 'span', { className: 'cc-label', text: S.specificItems } ),
			this.buildItemPicker(),
			this.fieldError( 'display_targets' ),
			this.fieldError( 'display_ids' )
		] );
		this.emptyWarning = h( 'p', { className: 'cc-warning', role: 'status', text: S.nothingChosen } );

		var radios = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-display-title' }, modes.map( function ( mode ) {
			var id = 'cc-display-' + mode[ 0 ];
			var input = h( 'input', { id: id, type: 'radio', name: 'cc-display-mode', value: mode[ 0 ], checked: draft.display_mode === mode[ 0 ] } );

			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					var wasAll = draft.display_mode === 'all';
					draft.display_mode = mode[ 0 ];
					self.syncDisplay();
					if ( wasAll && mode[ 0 ] !== 'all' ) {
						self.searchContent( self.searchInput.value.trim() );
					}
				}
			} );

			return h( 'label', { className: 'cc-radio', for: id }, [ input, h( 'span', { text: mode[ 1 ] } ) ] );
		} ) );

		var section = h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-display-title' }, [
			h( 'h2', { id: 'cc-display-title', text: S.displayTitle } ),
			radios,
			this.fieldError( 'display_mode' ),
			this.picker,
			this.emptyWarning
		] );

		this.syncDisplay();

		return section;
	};

	/**
	 * Shows the picker only when it applies, labels it for the current mode
	 * and warns when "only on chosen pages" has nothing chosen.
	 */
	SettingsView.prototype.syncDisplay = function () {
		var draft = this.draft;
		var mode = draft.display_mode;

		this.picker.hidden = mode === 'all';
		this.pickerLegend.textContent = mode === 'exclude' ? S.hideOn : S.showOn;
		this.emptyWarning.hidden = ! ( mode === 'include' && ! draft.display_targets.length && ! this.items.length );
	};

	SettingsView.prototype.buildTargets = function () {
		var self = this;
		var draft = this.draft;
		var labels = { home: S.targetHome, pages: S.targetPages, posts: S.targetPosts, products: S.targetProducts };

		return h( 'div', { className: 'cc-checks' }, this.data.targets.map( function ( target ) {
			var id = 'cc-target-' + target;
			var input = h( 'input', { id: id, type: 'checkbox', value: target, checked: draft.display_targets.indexOf( target ) !== -1 } );

			input.addEventListener( 'change', function () {
				draft.display_targets = draft.display_targets.filter( function ( t ) {
					return t !== target;
				} );
				if ( input.checked ) {
					draft.display_targets.push( target );
				}
				self.syncDisplay();
			} );

			return h( 'label', { className: 'cc-check', for: id }, [ input, h( 'span', { text: labels[ target ] || target } ) ] );
		} ) );
	};

	SettingsView.prototype.buildItemPicker = function () {
		var self = this;

		this.chips = h( 'ul', { className: 'cc-chips', 'aria-live': 'polite' } );
		this.results = h( 'ul', { className: 'cc-user-results cc-content-results' } );
		this.searchInput = h( 'input', {
			id: 'cc-content-search',
			className: 'cc-input',
			type: 'search',
			placeholder: S.findContent,
			'aria-label': S.findContent,
			onInput: function () {
				window.clearTimeout( self.searchTimer );
				self.searchTimer = window.setTimeout( function () {
					self.searchContent( self.searchInput.value.trim() );
				}, SEARCH_DELAY );
			}
		} );

		this.renderChips();

		return h( 'div', { className: 'cc-item-picker' }, [ this.chips, this.searchInput, this.results ] );
	};

	SettingsView.prototype.renderChips = function () {
		var self = this;

		this.chips.replaceChildren.apply( this.chips, this.items.map( function ( item ) {
			return h( 'li', { className: 'cc-chip', 'data-id': item.id }, [
				h( 'span', { className: 'cc-chip__type', text: item.type_label } ),
				h( 'span', { className: 'cc-chip__title', text: item.title } ),
				h( 'button', {
					type: 'button',
					className: 'cc-chip__remove',
					'aria-label': S.removeItem.replace( '%s', item.title ),
					title: S.removeItem.replace( '%s', item.title ),
					onClick: function () {
						self.toggleItem( item );
					}
				}, [ icon( 'close' ) ] )
			] );
		} ) );
		this.chips.hidden = ! this.items.length;
	};

	SettingsView.prototype.isChosen = function ( id ) {
		return this.items.some( function ( item ) {
			return item.id === id;
		} );
	};

	SettingsView.prototype.toggleItem = function ( item ) {
		if ( this.isChosen( item.id ) ) {
			this.items = this.items.filter( function ( chosen ) {
				return chosen.id !== item.id;
			} );
		} else if ( this.items.length >= this.data.limits.display_ids ) {
			this.panel.notify( S.tooManyItems.replace( '%d', fmt.number( this.data.limits.display_ids ) ), true );
			return;
		} else {
			this.items.push( item );
		}

		this.renderChips();
		this.syncDisplay();
		this.markResults();
		this.clearError( 'display_ids' );
	};

	SettingsView.prototype.markResults = function () {
		var self = this;

		this.results.querySelectorAll( '.cc-content' ).forEach( function ( button ) {
			var chosen = self.isChosen( parseInt( button.getAttribute( 'data-id' ), 10 ) );
			button.classList.toggle( 'is-selected', chosen );
			button.setAttribute( 'aria-pressed', chosen ? 'true' : 'false' );
		} );
	};

	SettingsView.prototype.searchContent = function ( term ) {
		var self = this;
		var ticket = ++this.searchTicket;
		var results = this.results;

		results.replaceChildren( h( 'li', { className: 'cc-hint', text: S.loading } ) );

		api.content( term ).then( function ( items ) {
			if ( ticket !== self.searchTicket ) {
				return;
			}
			if ( ! items.length ) {
				results.replaceChildren( h( 'li', { className: 'cc-hint', text: S.noContent } ) );
				return;
			}
			results.replaceChildren.apply( results, items.map( function ( item ) {
				return h( 'li', {}, [
					h( 'button', {
						type: 'button',
						className: 'cc-user cc-content',
						'data-id': item.id,
						onClick: function () {
							self.toggleItem( item );
						}
					}, [
						h( 'span', {}, [
							h( 'strong', { text: item.title } ),
							h( 'small', { text: item.type_label } )
						] ),
						icon( 'check', 'cc-content__check' )
					] )
				] );
			} ) );
			self.markResults();
		} ).catch( function ( error ) {
			if ( ticket === self.searchTicket ) {
				results.replaceChildren( h( 'li', { className: 'cc-field-error', text: error.message } ) );
			}
		} );
	};

	SettingsView.prototype.buildShortcode = function () {
		var self = this;
		var code = '[chatchi]';

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-shortcode-title' }, [
			h( 'h2', { id: 'cc-shortcode-title', text: S.shortcodeTitle } ),
			h( 'p', { className: 'cc-hint', text: S.shortcodeIntro } ),
			h( 'div', { className: 'cc-inline' }, [
				h( 'code', { className: 'cc-code', dir: 'ltr', text: code } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					onClick: function () {
						if ( ! navigator.clipboard ) {
							return;
						}
						navigator.clipboard.writeText( code ).then( function () {
							self.panel.notify( S.copied );
						}, function () {} );
					}
				}, [ icon( 'copy' ), S.copy ] )
			] )
		] );
	};

	/**
	 * How visitor IP addresses are read: directly, through Cloudflare, or
	 * through another proxy that names its header and addresses.
	 */
	SettingsView.prototype.buildProxy = function () {
		var self = this;
		var draft = this.draft;
		var modes = [
			[ 'direct', S.ipDirect ],
			[ 'cloudflare', S.ipCloudflare ],
			[ 'custom', S.ipCustom ]
		];

		var header = h( 'input', {
			id: 'cc-proxy-header',
			className: 'cc-input',
			type: 'text',
			dir: 'ltr',
			maxlength: '64',
			placeholder: 'X-Real-IP',
			autocomplete: 'off',
			spellcheck: 'false'
		} );
		header.value = draft.proxy_header;
		header.addEventListener( 'input', function () {
			draft.proxy_header = header.value;
			self.clearError( 'proxy_header' );
		} );

		var trusted = h( 'textarea', {
			id: 'cc-trusted-proxies',
			className: 'cc-input cc-textarea cc-textarea--code',
			rows: '4',
			dir: 'ltr',
			spellcheck: 'false',
			placeholder: '203.0.113.0/24\n2001:db8::/32',
			'aria-describedby': 'cc-trusted-hint'
		} );
		trusted.value = draft.trusted_proxies;

		var emptyWarning = h( 'p', { className: 'cc-warning', role: 'status', text: S.trustedEmpty } );

		function syncWarning() {
			emptyWarning.hidden = draft.trusted_proxies.trim() !== '';
		}

		trusted.addEventListener( 'input', function () {
			draft.trusted_proxies = trusted.value;
			self.clearError( 'trusted_proxies' );
			syncWarning();
		} );
		syncWarning();

		var custom = h( 'div', { className: 'cc-picker', hidden: draft.proxy_mode !== 'custom' }, [
			h( 'label', { className: 'cc-label', for: 'cc-proxy-header', text: S.proxyHeader } ),
			header,
			this.fieldError( 'proxy_header' ),
			h( 'label', { className: 'cc-label', for: 'cc-trusted-proxies', text: S.trustedProxies } ),
			trusted,
			h( 'p', { id: 'cc-trusted-hint', className: 'cc-hint', text: S.trustedHint } ),
			this.fieldError( 'trusted_proxies' ),
			emptyWarning
		] );

		var radios = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-ip-title' }, modes.map( function ( mode ) {
			var id = 'cc-proxy-' + mode[ 0 ];
			var input = h( 'input', { id: id, type: 'radio', name: 'cc-proxy-mode', value: mode[ 0 ], checked: draft.proxy_mode === mode[ 0 ] } );

			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					draft.proxy_mode = mode[ 0 ];
					custom.hidden = mode[ 0 ] !== 'custom';
					self.clearError( 'proxy_header' );
				}
			} );

			return h( 'label', { className: 'cc-radio', for: id }, [ input, h( 'span', { text: mode[ 1 ] } ) ] );
		} ) );

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-ip-title' }, [
			h( 'div', {}, [
				h( 'h2', { id: 'cc-ip-title', text: S.ipTitle } ),
				h( 'p', { className: 'cc-hint', text: S.ipIntro } )
			] ),
			radios,
			this.fieldError( 'proxy_mode' ),
			custom,
			this.data.your_ip ? h( 'p', { className: 'cc-hint cc-your-ip' }, [
				S.yourIp.split( '%s' )[ 0 ],
				h( 'bdi', { dir: 'ltr', className: 'cc-code-inline', text: this.data.your_ip } ),
				S.yourIp.split( '%s' )[ 1 ] || ''
			] ) : null
		] );
	};

	SettingsView.prototype.save = function () {
		var self = this;
		var section = this.section;
		var button = this.saveButton;

		Object.keys( this.errors ).forEach( function ( key ) {
			self.errors[ key ].hidden = true;
		} );

		button.disabled = true;
		button.textContent = S.saving;

		api.saveSettings( this.payload( section ) ).then( function ( data ) {
			self.apply( data, [ section ] );
			if ( section === 'appearance' ) {
				applyBrandColor( data.effective.primary_color );
			}
			self.render();
			self.panel.notify( S.saved );
		} ).catch( function ( error ) {
			button.disabled = false;
			button.textContent = S.save;

			var fields = ( error.data && error.data.fields ) || {};
			var shown = false;

			Object.keys( fields ).forEach( function ( key ) {
				if ( self.errors[ key ] ) {
					self.errors[ key ].textContent = fields[ key ];
					self.errors[ key ].hidden = false;
					shown = true;
				}
			} );

			self.panel.notify( error.message, true );

			if ( shown ) {
				var first = self.el.querySelector( '.cc-field-error:not([hidden])' );
				if ( first ) {
					first.scrollIntoView( { block: 'center' } );
				}
			}
		} );
	};

	/* ------------------------------- start ----------------------------- */

	new Panel().mount();
}() );
