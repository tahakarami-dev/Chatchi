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
	var TYPING_EVERY = 3000;
	var GROUP_GAP_MS = 2 * 60 * 1000;
	var SEARCH_DELAY = 300;

	/**
	 * Alerts for new visitor messages: a soft chime and, when the browser
	 * allows it, a desktop notification. Each agent turns them on or off
	 * for their own browser (kept in localStorage).
	 */
	var alerts = {
		key: 'chatchi_panel_alerts',
		context: null,
		on: function () {
			try {
				return window.localStorage.getItem( this.key ) !== 'off';
			} catch ( e ) {
				return true;
			}
		},
		set: function ( on ) {
			try {
				window.localStorage.setItem( this.key, on ? 'on' : 'off' );
			} catch ( e ) {
				// Private windows may refuse storage; the choice lasts for this page.
			}
			this.forced = on;
		},
		enabled: function () {
			return this.forced !== undefined ? this.forced : this.on();
		},
		// Browsers only allow sound after the person interacted with the page.
		unlock: function () {
			var Context = window.AudioContext || window.webkitAudioContext;
			if ( ! Context ) {
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
		chime: function () {
			var context = this.context;
			if ( ! context || context.state !== 'running' ) {
				return;
			}
			try {
				var now = context.currentTime;
				[ [ 660, 0 ], [ 990, 0.12 ], [ 1320, 0.24 ] ].forEach( function ( note ) {
					var oscillator = context.createOscillator();
					var gain = context.createGain();
					var at = now + note[ 1 ];
					oscillator.type = 'sine';
					oscillator.frequency.value = note[ 0 ];
					gain.gain.setValueAtTime( 0.0001, at );
					gain.gain.exponentialRampToValueAtTime( 0.14, at + 0.02 );
					gain.gain.exponentialRampToValueAtTime( 0.0001, at + 0.35 );
					oscillator.connect( gain );
					gain.connect( context.destination );
					oscillator.start( at );
					oscillator.stop( at + 0.4 );
				} );
			} catch ( e ) {
				// Sound is a nicety.
			}
		},
		desktop: function ( title, body, tag, onClick ) {
			if ( ! ( 'Notification' in window ) || window.Notification.permission !== 'granted' ) {
				return;
			}
			try {
				var notification = new window.Notification( title, { body: body, tag: tag, icon: config.iconUrl || undefined } );
				notification.onclick = function () {
					window.focus();
					onClick();
					notification.close();
				};
			} catch ( e ) {
				// Some browsers only allow notifications from a service worker.
			}
		}
	};

	var api = {
		me: function () {
			return request( 'GET', '/agent/me' );
		},
		canned: function () {
			return request( 'GET', '/agent/canned' );
		},
		reports: function ( range ) {
			return request( 'GET', '/agent/reports', { query: { range: range } } );
		},
		saveCanned: function ( id, data ) {
			return id ? request( 'PATCH', '/agent/canned/' + id, { body: data } ) : request( 'POST', '/agent/canned', { body: data } );
		},
		deleteCanned: function ( id ) {
			return request( 'DELETE', '/agent/canned/' + id );
		},
		upload: function ( id, file, clientId, onProgress ) {
			var form = new FormData();
			form.append( 'file', file, file.name );
			form.append( 'client_id', clientId );
			return client.upload( '/agent/conversations/' + id + '/attachments', form, onProgress );
		},
		note: function ( id, body, clientId ) {
			return request( 'POST', '/agent/conversations/' + id + '/notes', { body: { body: body, client_id: clientId } } );
		},
		assign: function ( id, agentId ) {
			return request( 'POST', '/agent/conversations/' + id + '/assign', { body: { agent_id: agentId } } );
		},
		setTags: function ( id, tags ) {
			return request( 'PUT', '/agent/conversations/' + id + '/tags', { body: { tags: tags } } );
		},
		tags: function () {
			return request( 'GET', '/agent/tags' );
		},
		team: function () {
			return request( 'GET', '/agent/team' );
		},
		botTest: function ( data ) {
			return request( 'POST', '/agent/settings/bot-test', { body: data } );
		},
		aiTest: function ( data ) {
			return request( 'POST', '/agent/settings/ai-test', { body: data } );
		},
		smsTest: function ( data ) {
			return request( 'POST', '/agent/settings/sms-test', { body: data } );
		},
		suggest: function ( id ) {
			return request( 'POST', '/agent/conversations/' + id + '/suggest' );
		},
		customer: function ( id ) {
			return request( 'GET', '/agent/conversations/' + id + '/customer' );
		},
		transcript: function ( id ) {
			return request( 'POST', '/agent/conversations/' + id + '/transcript' );
		},
		setStatus: function ( status ) {
			return request( 'PATCH', '/agent/me', { body: { status: status } } );
		},
		setTheme: function ( theme ) {
			return request( 'PATCH', '/agent/me', { body: { theme: theme } } );
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
		typing: function ( id, typing ) {
			return request( 'POST', '/agent/conversations/' + id + '/typing', { body: { typing: typing } } );
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
				return response.text().catch( function () {
					return '';
				} ).then( client.parseJson ).then( function ( data ) {
					if ( response.ok && data ) {
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
			assigned: '',
			tag: '',
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

		// Files dropped on an open conversation are sent.
		var dragDepth = 0;
		var canDrop = function ( event ) {
			var types = event.dataTransfer ? Array.from( event.dataTransfer.types || [] ) : [];
			return !! self.state.conv && self.state.conv.status !== 'closed' && types.indexOf( 'Files' ) !== -1;
		};

		this.convPane.setAttribute( 'data-drop-label', S.dropFiles );
		this.convPane.addEventListener( 'dragenter', function ( event ) {
			if ( canDrop( event ) ) {
				event.preventDefault();
				dragDepth++;
				self.convPane.classList.add( 'is-dropping' );
			}
		} );
		this.convPane.addEventListener( 'dragover', function ( event ) {
			if ( canDrop( event ) ) {
				event.preventDefault();
			}
		} );
		this.convPane.addEventListener( 'dragleave', function () {
			dragDepth = Math.max( 0, dragDepth - 1 );
			if ( ! dragDepth ) {
				self.convPane.classList.remove( 'is-dropping' );
			}
		} );
		this.convPane.addEventListener( 'drop', function ( event ) {
			dragDepth = 0;
			self.convPane.classList.remove( 'is-dropping' );
			if ( canDrop( event ) ) {
				event.preventDefault();
				Array.from( event.dataTransfer.files || [] ).slice( 0, 5 ).forEach( function ( file ) {
					self.sendFile( file );
				} );
			}
		} );
		this.infoPane = h( 'aside', { className: 'cc-info-pane', 'aria-label': S.visitorInfo } );
		this.page = h( 'main', { className: 'cc-page' } );
		this.shell = h( 'div', { className: 'cc-shell' }, [ this.rail, this.listPane, this.convPane, this.infoPane, this.page ] );
		root.appendChild( this.shell );
		this.view = 'inbox';
		this.agentsView = config.canManageAgents ? new AgentsView( this ) : null;
		this.settingsView = config.canManageSettings ? new SettingsView( this ) : null;
		this.cannedView = config.canManageSettings ? new CannedView( this ) : null;
		this.reportsView = config.canManageSettings ? new ReportsView( this ) : null;
		this.canned = [];
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

		this.loadCanned();
		this.loadTags();

		var unlock = function () {
			alerts.unlock();
			document.removeEventListener( 'pointerdown', unlock );
			document.removeEventListener( 'keydown', unlock );
		};
		document.addEventListener( 'pointerdown', unlock );
		document.addEventListener( 'keydown', unlock );

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
			this.reportsView ? railLink( 'reports', '#/reports', S.reports, 'chart' ) : null,
			this.cannedView ? railLink( 'canned', '#/canned', S.canned, 'zap' ) : null,
			this.settingsView ? railLink( 'settings', '#/settings', S.settings, 'sliders' ) : null,
			h( 'span', { className: 'cc-rail-spacer' } ),
			this.buildThemeButton(),
			this.buildAlertsButton(),
			agent ? this.buildStatusButton( agent ) : null,
			h( 'a', { className: 'cc-rail-item', href: config.adminUrl, title: S.backToWp, 'aria-label': S.backToWp }, [ icon( 'exit', 'cc-flip' ) ] )
		);
	};

	/**
	 * Turns new-message alerts on or off for this browser; turning them on
	 * also asks for permission to show desktop notifications.
	 */
	/**
	 * Light, dark, or the device's choice; saved on the agent's account.
	 */
	var PANEL_THEMES = [ 'light', 'dark', 'auto' ];

	Panel.prototype.buildThemeButton = function () {
		var self = this;
		var current = document.documentElement.getAttribute( 'data-theme' ) || 'light';
		var labels = { light: S.themeLight, dark: S.themeDark, auto: S.themeAuto };
		var icons = { light: 'sun', dark: 'moon', auto: 'monitor' };
		var label = S.panelTheme + ': ' + labels[ current ];

		return h( 'button', {
			type: 'button',
			className: 'cc-rail-item cc-theme-toggle',
			title: label,
			'aria-label': label,
			onClick: function () {
				var next = PANEL_THEMES[ ( PANEL_THEMES.indexOf( current ) + 1 ) % PANEL_THEMES.length ];
				document.documentElement.setAttribute( 'data-theme', next );
				self.renderRail();
				self.notify( S.panelTheme + ': ' + labels[ next ] );
				api.setTheme( next ).catch( function ( error ) {
					self.notify( error.message, true );
				} );
			}
		}, [ icon( icons[ current ] ) ] );
	};

	Panel.prototype.buildAlertsButton = function () {
		var self = this;
		var on = alerts.enabled();
		var label = on ? S.alertsOn : S.alertsOff;

		return h( 'button', {
			type: 'button',
			className: 'cc-rail-item cc-alerts' + ( on ? '' : ' is-off' ),
			title: label,
			'aria-label': label,
			'aria-pressed': on ? 'true' : 'false',
			onClick: function () {
				alerts.set( ! on );
				alerts.unlock();
				if ( ! on && 'Notification' in window && window.Notification.permission === 'default' ) {
					window.Notification.requestPermission();
				}
				self.notify( ! on ? S.alertsEnabled : S.alertsDisabled );
				self.renderRail();
			}
		}, [ icon( 'bell' ) ] );
	};

	/**
	 * Alerts about visitor messages that arrived since the last refresh,
	 * unless the agent is already looking at that conversation.
	 *
	 * @param {Array}   items  Inbox items just loaded.
	 * @param {boolean} notify False to only remember what was seen.
	 */
	Panel.prototype.detectNew = function ( items, notify ) {
		var self = this;
		var seen = this.seenMessages || ( this.seenMessages = {} );
		var fresh = [];

		items.forEach( function ( item ) {
			var last = item.last_message;
			var before = seen[ item.id ];

			if ( ! last ) {
				return;
			}
			seen[ item.id ] = Math.max( before || 0, last.id );

			// A conversation seen for the first time (e.g. after "load more")
			// only counts when its last message is recent.
			var isNew = before === undefined ? Date.now() - new Date( last.created_at ).getTime() < 2 * 60 * 1000 : last.id > before;

			if ( notify && isNew && last.sender.type === 'visitor' && item.status !== 'closed' ) {
				var watching = item.id === self.state.selectedId && ! document.hidden;
				if ( ! watching ) {
					fresh.push( item );
				}
			}
		} );

		if ( ! fresh.length || ! alerts.enabled() ) {
			return;
		}

		alerts.chime();
		fresh.slice( 0, 3 ).forEach( function ( item ) {
			var last = item.last_message;
			alerts.desktop( visitorName( item.visitor ), ( last.has_attachment ? '📎 ' : '' ) + last.body.slice( 0, 140 ), 'chatchi-' + item.id, function () {
				window.location.hash = '#/conversations/' + item.id;
			} );
		} );
	};

	/**
	 * The agent's own avatar, with a dot for their availability; clicking
	 * switches between online and away. Away agents do not count as
	 * available for visitors (see Availability on the server).
	 */
	Panel.prototype.buildStatusButton = function ( agent ) {
		var self = this;
		var online = agent.status !== 'away';
		var label = agent.name + ' — ' + ( online ? S.statusOnline : S.statusAway ) + '. ' + ( online ? S.goAway : S.goOnline );

		return h( 'button', {
			type: 'button',
			className: 'cc-status' + ( online ? ' is-online' : ' is-away' ),
			title: label,
			'aria-label': label,
			'aria-pressed': online ? 'true' : 'false',
			onClick: function ( event ) {
				var button = event.currentTarget;
				button.disabled = true;
				api.setStatus( online ? 'away' : 'online' ).then( function ( updated ) {
					self.state.agent = updated;
					self.renderRail();
					self.notify( updated.status === 'away' ? S.nowAway : S.nowOnline );
				} ).catch( function ( error ) {
					button.disabled = false;
					self.notify( error.message, true );
				} );
			}
		}, [ avatar( agent.name, 'rail', agent.avatar_url ), h( 'span', { className: 'cc-status__dot', 'aria-hidden': 'true' } ) ] );
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

		// Whose conversations, and which tag.
		var assignedOptions = [ [ '', S.everyone ], [ 'mine', S.mine ], [ 'unassigned', S.unassigned ] ];
		this.assignedFilter = h( 'div', { className: 'cc-filter-chips', role: 'radiogroup', 'aria-label': S.assignedFilter }, assignedOptions.map( function ( option ) {
			return h( 'button', {
				type: 'button',
				className: 'cc-filter-chip',
				role: 'radio',
				'aria-checked': self.state.assigned === option[ 0 ] ? 'true' : 'false',
				text: option[ 1 ],
				onClick: function ( event ) {
					self.state.assigned = option[ 0 ];
					Array.from( self.assignedFilter.children ).forEach( function ( chip ) {
						chip.setAttribute( 'aria-checked', chip === event.currentTarget ? 'true' : 'false' );
					} );
					self.loadList();
				}
			} );
		} ) );
		this.tagFilter = h( 'select', {
			className: 'cc-tag-filter',
			'aria-label': S.tagFilter,
			onChange: function () {
				self.state.tag = self.tagFilter.value;
				self.loadList();
			}
		} );
		this.renderTagFilter();
		this.listTitle = h( 'h1', { text: S.conversations } );
		this.listBody = h( 'ul', { className: 'cc-list', 'aria-live': 'polite', 'aria-busy': 'true' } );

		this.listPane.replaceChildren(
			h( 'div', { className: 'cc-list-head' }, [
				this.listTitle,
				h( 'div', { className: 'cc-search' }, [ icon( 'search' ), this.searchInput ] ),
				h( 'div', { className: 'cc-filters' }, [ this.assignedFilter, this.tagFilter ] ),
				this.tabs
			] ),
			this.listBody
		);
	};

	Panel.prototype.renderTagFilter = function () {
		var select = this.tagFilter;
		var current = this.state.tag;
		var tags = ( this.popularTags || [] ).map( function ( item ) {
			return item.tag;
		} );

		if ( ! select ) {
			return;
		}

		if ( current && tags.indexOf( current ) === -1 ) {
			tags.unshift( current );
		}

		select.replaceChildren.apply( select, [ h( 'option', { value: '', text: S.allTags } ) ].concat( tags.map( function ( tag ) {
			return h( 'option', { value: tag, text: '# ' + tag, selected: tag === current } );
		} ) ) );
		select.hidden = ! tags.length;
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

		return api.inbox( { status: this.state.status, search: this.state.search, assigned: this.state.assigned, tag: this.state.tag, page: 1, per_page: perPage } ).then( function ( page ) {
			if ( ticket !== self.listRequest ) {
				return;
			}
			list.items = page.items;
			// Only refreshes alert; a new filter or search just shows other items.
			self.detectNew( page.items, !! refresh && self.alertsReady );
			self.alertsReady = true;
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

		api.inbox( { status: this.state.status, search: this.state.search, assigned: this.state.assigned, tag: this.state.tag, page: page, per_page: PER_PAGE } ).then( function ( result ) {
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
		var preview = last ? ( last.is_mine ? S.you + ': ' : '' ) + ( last.has_attachment ? '📎 ' : '' ) + last.body.replace( /\s+/g, ' ' ) : S.noMessages;
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
					item.rating ? h( 'span', { className: 'cc-item__rating cc-item__rating--' + ( item.rating.score >= 4 ? 'good' : item.rating.score === 3 ? 'ok' : 'bad' ), title: fmt.format( S.ratedStars, item.rating.score ), 'aria-label': fmt.format( S.ratedStars, item.rating.score ) }, [ icon( 'star' ), fmt.number( item.rating.score ) ] ) : null,
					item.unread_count > 0 ? h( 'span', { className: 'cc-count', 'aria-label': fmt.format( S.unread, item.unread_count ), text: fmt.number( item.unread_count ) } ) : null
				] ),
				item.tags && item.tags.length ? h( 'span', { className: 'cc-item__tags' }, item.tags.slice( 0, 3 ).map( function ( tag ) {
					return h( 'span', { className: 'cc-item__tag', text: '#' + tag } );
				} ) ) : null
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
		} else if ( this.cannedView && hash === '#/canned' ) {
			page = 'canned';
		} else if ( this.reportsView && hash === '#/reports' ) {
			page = 'reports';
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
		if ( page === 'canned' ) {
			this.cannedView.show();
			return;
		}
		if ( page === 'reports' ) {
			this.reportsView.show();
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
		this.noteMode = false;
		this.assignMenu = false;
		state.convLoading = !! id;
		state.visitorTyping = false;
		this.lastReadMarked = 0;
		this.convTicks = 0;
		this.typingSentAt = 0;

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
			self.state.visitorTyping = !! page.typing && ! page.items.length;
			self.updateTyping();
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
		var before = JSON.stringify( [ this.state.conv.status, this.state.conv.visitor, this.state.conv.agent, this.state.conv.tags ] );
		this.state.conv = detail;

		if ( JSON.stringify( [ detail.status, detail.visitor, detail.agent, detail.tags ] ) !== before ) {
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
		var pending = { clientId: Core.uuid(), body: text, failed: false, convId: this.state.selectedId, note: !! this.noteMode };

		// Sending ends the typing signal on the server as well.
		this.typingSentAt = 0;

		this.state.pending.push( pending );
		this.drafts[ pending.convId ] = '';
		this.renderLog();
		this.scrollToBottom();
		this.deliver( pending );
	};

	Panel.prototype.deliver = function ( pending ) {
		var self = this;

		// A file that can never be sent: clicking the error removes it.
		if ( pending.fatal ) {
			this.state.pending = this.state.pending.filter( function ( p ) {
				return p !== pending;
			} );
			this.renderLog();
			return;
		}

		pending.failed = false;
		this.renderLog();

		var sending = pending.file ? api.upload( pending.convId, pending.file, pending.clientId, function ( progress ) {
			var bar = self.convPane.querySelector( '[data-key="' + pending.clientId + '"] .cc-progress span' );
			pending.progress = progress;
			if ( bar ) {
				bar.style.width = Math.round( progress * 100 ) + '%';
			}
		} ) : ( pending.note ? api.note( pending.convId, pending.body, pending.clientId ) : api.send( pending.convId, pending.body, pending.clientId ) );

		sending.then( function ( message ) {
			if ( pending.preview ) {
				URL.revokeObjectURL( pending.preview );
			}
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
			pending.fatal = [ 'chatchi_attachment_type', 'chatchi_attachment_too_large' ].indexOf( error.code ) !== -1;
			pending.reason = pending.file && error.status >= 400 && error.status < 500 ? error.message : '';
			self.renderLog();
		} );
	};

	/**
	 * Sends a file the agent picked, pasted or dropped.
	 */
	Panel.prototype.sendFile = function ( file ) {
		var settings = ( this.state.agent && this.state.agent.attachments ) || { max_bytes: 0 };
		var pending = { clientId: Core.uuid(), body: file.name, file: file, progress: 0, failed: false, convId: this.state.selectedId };

		if ( /^image\/(jpeg|png|gif|webp)$/.test( file.type ) && window.URL && URL.createObjectURL ) {
			pending.preview = URL.createObjectURL( file );
		}

		this.state.pending.push( pending );

		if ( settings.max_bytes && file.size > settings.max_bytes ) {
			pending.failed = true;
			pending.fatal = true;
			pending.reason = S.fileTooLarge.replace( '%s', Core.fileSize( settings.max_bytes, config.locale ) );
			this.renderLog();
			this.scrollToBottom();
			return;
		}

		this.scrollToBottom();
		this.deliver( pending );
	};

	Panel.prototype.buildAttachment = function ( attachment ) {
		if ( attachment.is_image ) {
			return h( 'a', { className: 'cc-bubble cc-bubble--image', href: client.fileUrl( attachment.url ), target: '_blank', rel: 'noopener', title: attachment.name }, [
				h( 'img', { src: client.fileUrl( attachment.url ), alt: attachment.name, loading: 'lazy', width: attachment.width ? String( attachment.width ) : null, height: attachment.height ? String( attachment.height ) : null } )
			] );
		}

		return h( 'a', { className: 'cc-bubble cc-file', href: client.fileUrl( attachment.url, true ), download: attachment.name, title: S.download + ': ' + attachment.name }, [
			h( 'span', { className: 'cc-file__icon' }, [ icon( 'file' ) ] ),
			h( 'span', { className: 'cc-file__text' }, [
				h( 'span', { className: 'cc-file__name', text: attachment.name } ),
				h( 'small', { text: Core.fileSize( attachment.size, config.locale ) } )
			] ),
			icon( 'download' )
		] );
	};

	Panel.prototype.buildUpload = function ( pending ) {
		var bar = pending.failed ? null : h( 'span', { className: 'cc-progress', role: 'progressbar', 'aria-label': S.uploading }, [
			h( 'span', { style: 'width:' + Math.round( ( pending.progress || 0 ) * 100 ) + '%' } )
		] );

		if ( pending.preview ) {
			return h( 'div', { className: 'cc-bubble cc-bubble--image is-uploading' }, [ h( 'img', { src: pending.preview, alt: pending.body } ), bar ] );
		}

		return h( 'div', { className: 'cc-bubble cc-file is-uploading' }, [
			h( 'span', { className: 'cc-file__icon' }, [ icon( 'file' ) ] ),
			h( 'span', { className: 'cc-file__text' }, [
				h( 'span', { className: 'cc-file__name', text: pending.body } ),
				h( 'small', { text: Core.fileSize( pending.file.size, config.locale ) } ),
				bar
			] )
		] );
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
				conv.status !== 'closed' ? this.buildAssign() : null,
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

	/**
	 * "Transfer" button with a menu of active agents (a dot shows who is
	 * online), plus "unassign".
	 */
	Panel.prototype.buildAssign = function () {
		var self = this;
		var conv = this.state.conv;
		var current = conv.agent ? conv.agent.id : 0;
		var menu = h( 'div', { className: 'cc-menu', role: 'menu', hidden: ! this.assignMenu, 'aria-label': S.transfer } );
		var button = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary cc-assign',
			'aria-haspopup': 'menu',
			'aria-expanded': this.assignMenu ? 'true' : 'false',
			title: S.transfer,
			onClick: function () {
				self.assignMenu = ! self.assignMenu;
				menu.hidden = ! self.assignMenu;
				button.setAttribute( 'aria-expanded', self.assignMenu ? 'true' : 'false' );
				if ( self.assignMenu ) {
					fill();
				}
			}
		}, [ icon( 'transfer' ), h( 'span', { text: conv.agent ? conv.agent.name : S.unassigned } ) ] );

		function choose( agentId ) {
			self.assignMenu = false;
			menu.hidden = true;
			api.assign( conv.id, agentId ).then( function ( detail ) {
				if ( self.state.selectedId !== conv.id ) {
					return;
				}
				self.applyDetail( detail );
				self.renderConversation( true );
				self.pollMessages( conv.id );
				self.loadList( true );
				self.notify( detail.agent ? S.transferred.replace( '%s', detail.agent.name ) : S.unassignedDone );
			} ).catch( function ( error ) {
				self.notify( error.message, true );
			} );
		}

		function fill() {
			menu.replaceChildren( h( 'p', { className: 'cc-menu__hint', text: S.loading } ) );
			api.team().then( function ( team ) {
				menu.replaceChildren.apply( menu, team.map( function ( agent ) {
					return h( 'button', {
						type: 'button',
						className: 'cc-menu__item' + ( agent.id === current ? ' is-current' : '' ),
						role: 'menuitemradio',
						'aria-checked': agent.id === current ? 'true' : 'false',
						onClick: function () {
							if ( agent.id !== current ) {
								choose( agent.id );
							}
						}
					}, [
						h( 'span', { className: 'cc-menu__dot' + ( agent.online ? ' is-online' : '' ), 'aria-label': agent.online ? S.statusOnline : S.statusAway } ),
						h( 'span', { text: agent.is_me ? agent.name + ' (' + S.me + ')' : agent.name } ),
						agent.id === current ? icon( 'check' ) : null
					] );
				} ).concat( current ? [ h( 'button', {
					type: 'button',
					className: 'cc-menu__item cc-menu__item--muted',
					role: 'menuitem',
					text: S.unassign,
					onClick: function () {
						choose( 0 );
					}
				} ) ] : [] ) );
			} ).catch( function ( error ) {
				menu.replaceChildren( h( 'p', { className: 'cc-menu__hint', text: error.message } ) );
			} );
		}

		if ( this.assignMenu ) {
			fill();
		}

		var wrap = h( 'div', { className: 'cc-menu-wrap' }, [ button, menu ] );

		wrap.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && self.assignMenu ) {
				event.stopPropagation();
				self.assignMenu = false;
				menu.hidden = true;
				button.setAttribute( 'aria-expanded', 'false' );
				button.focus();
			}
		} );
		wrap.addEventListener( 'focusout', function ( event ) {
			if ( self.assignMenu && ( ! event.relatedTarget || ! wrap.contains( event.relatedTarget ) ) ) {
				self.assignMenu = false;
				menu.hidden = true;
				button.setAttribute( 'aria-expanded', 'false' );
			}
		} );

		return wrap;
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
			return { key: m.id, mine: m.is_mine, type: m.sender.type, sender: m.sender, body: m.body, internal: !! m.internal, attachment: m.attachment, date: new Date( m.created_at ), read: !! m.read_at, isNew: !! self.newIds[ m.id ] };
		} ).concat( state.pending.map( function ( p ) {
			return { key: p.clientId, mine: true, type: 'agent', sender: self.state.agent, body: p.body, internal: !! p.note, upload: p.file ? p : null, date: new Date(), pending: ! p.failed, failed: p.failed, ref: p, isNew: ! p.shown };
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
		return ! a.internal && ! b.internal &&
			a.mine === b.mine &&
			a.type === b.type &&
			( a.sender && b.sender ? a.sender.name === b.sender.name : true ) &&
			fmt.dayKey( a.date ) === fmt.dayKey( b.date ) &&
			Math.abs( b.date - a.date ) < GROUP_GAP_MS;
	};

	/**
	 * An internal note: a card only agents see.
	 */
	Panel.prototype.buildNote = function ( entry ) {
		var self = this;
		var author = ( entry.sender && entry.sender.name ) || S.appName;
		var body = h( 'div', { className: 'cc-note__body' } );

		body.appendChild( Core.linkify( entry.body ) );

		return h( 'div', { className: 'cc-note' + ( entry.pending ? ' is-pending' : '' ) + ( entry.failed ? ' is-failed' : '' ) + ( entry.isNew ? ' is-new' : '' ), 'data-key': String( entry.key ) }, [
			h( 'div', { className: 'cc-note__head' }, [
				icon( 'lock' ),
				h( 'strong', { text: S.internalNote } ),
				h( 'span', { text: author } ),
				h( 'time', { text: fmt.time( entry.date ) } )
			] ),
			body,
			entry.failed ? h( 'button', {
				type: 'button',
				className: 'cc-retry',
				text: S.sendFailed,
				onClick: function () {
					self.deliver( entry.ref );
				}
			} ) : null
		] );
	};

	Panel.prototype.buildMessage = function ( entry, groupStart, groupEnd, visitor ) {
		var self = this;

		if ( entry.type === 'system' ) {
			return entry.internal ? h( 'div', { className: 'cc-event' }, [ icon( 'users' ), h( 'span', { text: entry.body } ), h( 'time', { text: fmt.time( entry.date ) } ) ] ) : h( 'div', { className: 'cc-day', text: entry.body } );
		}

		if ( entry.internal ) {
			return this.buildNote( entry );
		}

		var isBot = !! ( entry.sender && entry.sender.bot );
		// The bot writes for the team: its messages sit on the team's side.
		var classes = [ 'cc-row', entry.mine || isBot ? 'cc-row--mine' : 'cc-row--theirs' ];
		if ( isBot ) {
			classes.push( 'cc-row--bot' );
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

		var senderName = entry.type === 'visitor' ? visitor : ( entry.sender && entry.sender.name ) || S.appName;
		var stack = h( 'div', { className: 'cc-stack' } );

		if ( ( ! entry.mine || isBot ) && groupStart ) {
			stack.appendChild( isBot
				? h( 'span', { className: 'cc-sender cc-sender--bot' }, [ icon( 'sparkles' ), senderName ] )
				: h( 'span', { className: 'cc-sender', text: senderName } ) );
		}

		var bubble;

		if ( entry.attachment ) {
			bubble = this.buildAttachment( entry.attachment );
		} else if ( entry.upload ) {
			bubble = this.buildUpload( entry.upload );
			entry.upload.shown = true;
		} else {
			bubble = h( 'div', { className: 'cc-bubble' } );
			bubble.appendChild( Core.linkify( entry.body ) );
		}
		stack.appendChild( bubble );

		if ( entry.failed ) {
			stack.appendChild( h( 'button', {
				type: 'button',
				className: 'cc-retry',
				text: entry.ref.reason || S.sendFailed,
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

		var row = h( 'div', { className: classes.join( ' ' ), 'data-key': String( entry.key ) } );

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

	/**
	 * Lets the visitor see that an agent is writing. Sent at most every few
	 * seconds while typing, and once when the text is cleared.
	 */
	Panel.prototype.signalTyping = function ( id, typing ) {
		var now = Date.now();

		if ( ! id || ! this.state.conv || this.state.conv.status === 'closed' ) {
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

		api.typing( id, typing ).catch( function () {
			// A lost typing signal only hides an indicator; nothing to retry.
		} );
	};

	/**
	 * Shows or hides "typing…" for the visitor, in place.
	 */
	Panel.prototype.updateTyping = function () {
		var line = this.convPane.querySelector( '.cc-typing' );

		if ( line ) {
			line.hidden = ! this.state.visitorTyping;
		}
	};

	Panel.prototype.buildComposer = function () {
		var self = this;
		var id = this.state.selectedId;
		var send = h( 'button', { type: 'submit', className: 'cc-send', 'aria-label': S.send, title: S.send, disabled: ! ( this.drafts[ id ] || '' ).trim() }, [ icon( 'send', 'cc-flip' ) ] );
		var textarea = h( 'textarea', {
			rows: '1',
			placeholder: S.placeholder,
			'aria-label': S.placeholder,
			'aria-autocomplete': 'list',
			'aria-controls': 'cc-canned-picker',
			value: this.drafts[ id ] || ''
		} );

		// Saved replies: opened with the ⚡ button, or by typing "/" at the
		// start of an empty message ("/ship" filters as you type).
		var picker = { open: false, slash: false, index: 0, matches: [] };
		var list = h( 'div', { id: 'cc-canned-picker', className: 'cc-canned-picker', role: 'listbox', 'aria-label': S.cannedInsert, hidden: true } );
		var cannedButton = h( 'button', {
			type: 'button',
			className: 'cc-tool',
			title: S.cannedInsert,
			'aria-label': S.cannedInsert,
			'aria-expanded': 'false',
			'aria-controls': 'cc-canned-picker',
			onClick: function () {
				if ( picker.open ) {
					closePicker();
				} else {
					openPicker( '', false );
				}
			}
		}, [ icon( 'zap' ) ] );

		// ✨ An AI draft of the reply, to edit before sending.
		var suggestButton = config.aiSuggest ? h( 'button', {
			type: 'button',
			className: 'cc-tool cc-tool--ai',
			title: S.aiSuggest,
			'aria-label': S.aiSuggest,
			onClick: function () {
				var conv = self.state.conv;
				if ( ! conv || suggestButton.classList.contains( 'is-busy' ) ) {
					return;
				}
				suggestButton.classList.add( 'is-busy' );
				suggestButton.setAttribute( 'aria-busy', 'true' );
				api.suggest( conv.id ).then( function ( result ) {
					textarea.value = result.text;
					textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					textarea.focus();
					textarea.setSelectionRange( textarea.value.length, textarea.value.length );
				} ).catch( function ( error ) {
					self.notify( error.message, true );
				} ).then( function () {
					suggestButton.classList.remove( 'is-busy' );
					suggestButton.removeAttribute( 'aria-busy' );
				} );
			}
		}, [ icon( 'sparkles' ) ] ) : null;

		function fill( body ) {
			var conv = self.state.conv;
			var name = ( conv && conv.visitor && conv.visitor.name ) || '';
			var agent = self.state.agent ? self.state.agent.name : '';

			return body.replace( /\{name\}/g, name ).replace( /\{agent\}/g, agent );
		}

		function matchesFor( query ) {
			query = query.toLowerCase();

			return self.canned.filter( function ( item ) {
				return ! query || item.shortcut.indexOf( query ) === 0 || item.title.toLowerCase().indexOf( query ) !== -1 || item.body.toLowerCase().indexOf( query ) !== -1;
			} ).slice( 0, 50 );
		}

		function renderPicker() {
			list.replaceChildren();

			if ( ! picker.matches.length ) {
				textarea.removeAttribute( 'aria-activedescendant' );
				list.appendChild( h( 'p', { className: 'cc-canned-picker__empty' }, [
					S.cannedNone,
					config.canManageSettings ? h( 'a', { href: '#/canned', text: S.cannedManage } ) : null
				] ) );
				return;
			}

			picker.matches.forEach( function ( item, i ) {
				list.appendChild( h( 'div', {
					id: 'cc-canned-option-' + item.id,
					className: 'cc-canned-option' + ( i === picker.index ? ' is-active' : '' ),
					role: 'option',
					'aria-selected': i === picker.index ? 'true' : 'false',
					// mousedown, so the text box keeps focus.
					onMousedown: function ( event ) {
						event.preventDefault();
						choose( item );
					}
				}, [
					h( 'span', { className: 'cc-canned-option__head' }, [
						h( 'strong', { text: item.title } ),
						item.shortcut ? h( 'code', { dir: 'ltr', text: '/' + item.shortcut } ) : null
					] ),
					h( 'span', { className: 'cc-canned-option__body', text: fill( item.body ) } )
				] ) );
			} );

			textarea.setAttribute( 'aria-activedescendant', 'cc-canned-option-' + picker.matches[ picker.index ].id );

			var active = list.querySelector( '.is-active' );
			if ( active && active.scrollIntoView ) {
				active.scrollIntoView( { block: 'nearest' } );
			}
		}

		function openPicker( query, slash ) {
			picker.open = true;
			picker.slash = slash;
			picker.index = 0;
			picker.matches = matchesFor( query );
			list.hidden = false;
			cannedButton.setAttribute( 'aria-expanded', 'true' );
			renderPicker();
			if ( ! slash ) {
				textarea.focus();
			}
		}

		function closePicker() {
			if ( ! picker.open ) {
				return;
			}
			picker.open = false;
			list.hidden = true;
			cannedButton.setAttribute( 'aria-expanded', 'false' );
			textarea.removeAttribute( 'aria-activedescendant' );
		}

		function choose( item ) {
			var text = fill( item.body );

			if ( picker.slash ) {
				textarea.value = text;
			} else if ( textarea.setRangeText ) {
				textarea.setRangeText( text, textarea.selectionStart, textarea.selectionEnd, 'end' );
			} else {
				textarea.value += text;
			}

			closePicker();
			textarea.dispatchEvent( new Event( 'input' ) );
			textarea.focus();
		}

		var fileInput = h( 'input', { type: 'file', className: 'cc-sr', tabindex: '-1', 'aria-hidden': 'true', multiple: true } );
		var attachButton = h( 'button', {
			type: 'button',
			className: 'cc-tool',
			title: S.attach,
			'aria-label': S.attach,
			onClick: function () {
				fileInput.click();
			}
		}, [ icon( 'paperclip' ) ] );

		if ( this.state.agent && this.state.agent.attachments ) {
			fileInput.accept = this.state.agent.attachments.accept;
		}

		fileInput.addEventListener( 'change', function () {
			Array.from( fileInput.files || [] ).slice( 0, 5 ).forEach( function ( file ) {
				self.sendFile( file );
			} );
			fileInput.value = '';
		} );

		// Pasting a screenshot sends it.
		textarea.addEventListener( 'paste', function ( event ) {
			var files = event.clipboardData ? Array.from( event.clipboardData.files || [] ) : [];
			if ( files.length ) {
				event.preventDefault();
				files.slice( 0, 5 ).forEach( function ( file ) {
					self.sendFile( file );
				} );
			}
		} );

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
			closePicker();
			refresh();
			self.send( text );
		}

		textarea.addEventListener( 'input', function () {
			self.drafts[ id ] = textarea.value;
			refresh();
			self.signalTyping( id, !! textarea.value.trim() );

			var slash = /^\/(\S*)$/.exec( textarea.value );
			if ( slash ) {
				openPicker( slash[ 1 ], true );
			} else if ( picker.slash ) {
				closePicker();
			}
		} );

		textarea.addEventListener( 'keydown', function ( event ) {
			if ( picker.open && ! event.isComposing ) {
				var count = picker.matches.length;

				if ( ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) && count ) {
					event.preventDefault();
					picker.index = ( picker.index + ( event.key === 'ArrowDown' ? 1 : count - 1 ) ) % count;
					renderPicker();
					return;
				}
				if ( ( event.key === 'Enter' || event.key === 'Tab' ) && ! event.shiftKey && count ) {
					event.preventDefault();
					choose( picker.matches[ picker.index ] );
					return;
				}
				if ( event.key === 'Escape' ) {
					event.preventDefault();
					event.stopPropagation();
					closePicker();
					return;
				}
			}

			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				submit();
			}
		} );

		window.requestAnimationFrame( refresh );

		// Reply to the visitor, or write an internal note only agents see.
		function setMode( note, keepFocus ) {
			self.noteMode = note;
			inner.classList.toggle( 'is-note', note );
			replyTab.setAttribute( 'aria-selected', note ? 'false' : 'true' );
			noteTab.setAttribute( 'aria-selected', note ? 'true' : 'false' );
			textarea.placeholder = note ? S.notePlaceholder : S.placeholder;
			textarea.setAttribute( 'aria-label', textarea.placeholder );
			attachButton.hidden = note;
			send.title = note ? S.addNote : S.send;
			send.setAttribute( 'aria-label', send.title );
			if ( ! keepFocus ) {
				textarea.focus();
			}
		}

		var replyTab = h( 'button', {
			type: 'button',
			className: 'cc-compose-mode__tab',
			role: 'tab',
			'aria-selected': 'true',
			text: S.reply,
			onClick: function () {
				setMode( false );
			}
		} );
		var noteTab = h( 'button', {
			type: 'button',
			className: 'cc-compose-mode__tab cc-compose-mode__tab--note',
			role: 'tab',
			'aria-selected': 'false',
			onClick: function () {
				setMode( true );
			}
		}, [ icon( 'lock' ), S.internalNote ] );

		var inner = h( 'div', { className: 'cc-composer__inner' }, [
			h( 'div', { className: 'cc-compose-mode', role: 'tablist', 'aria-label': S.composeMode }, [ replyTab, noteTab ] ),
			list,
			h( 'form', {
				onSubmit: function ( event ) {
					event.preventDefault();
					submit();
				}
			}, [ cannedButton, suggestButton, attachButton, fileInput, textarea, send ] )
		] );

		if ( this.noteMode ) {
			window.requestAnimationFrame( function () {
				setMode( true, true );
			} );
		}

		// Leaving the composer closes the picker.
		inner.addEventListener( 'focusout', function ( event ) {
			if ( ! event.relatedTarget || ! inner.contains( event.relatedTarget ) ) {
				closePicker();
			}
		} );

		return h( 'div', { className: 'cc-composer' }, [
			h( 'div', { className: 'cc-typing', role: 'status', hidden: ! this.state.visitorTyping }, [
				h( 'span', { className: 'cc-typing__dots', 'aria-hidden': 'true' }, [ h( 'span' ), h( 'span' ), h( 'span' ) ] ),
				h( 'span', { text: S.visitorTyping } )
			] ),
			inner
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

		if ( conv.rating ) {
			rows.push( row( 'star', S.rating, h( 'div', { className: 'cc-info-rating' }, [
				h( 'span', { className: 'cc-info-rating__stars', 'aria-label': fmt.format( S.ratedStars, conv.rating.score ), text: '★★★★★'.slice( 0, conv.rating.score ) + '☆☆☆☆☆'.slice( 0, 5 - conv.rating.score ) } ),
				conv.rating.comment ? h( 'p', { className: 'cc-info-rating__comment', text: conv.rating.comment } ) : null
			] ) ) );
		}

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
			h( 'ul', { className: 'cc-info-list' }, rows ),
			visitor.email ? h( 'div', { className: 'cc-info-actions' }, [
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary cc-button--sm',
					onClick: function ( event ) {
						var button = event.currentTarget;
						button.disabled = true;
						api.transcript( conv.id ).then( function ( result ) {
							self.notify( S.transcriptSent.replace( '%s', result.sent_to ) );
						} ).catch( function ( error ) {
							self.notify( error.message, true );
						} ).then( function () {
							button.disabled = false;
						} );
					}
				}, [ icon( 'mail' ), S.sendTranscript ] )
			] ) : null,
			this.buildCustomer( conv ),
			this.buildTagEditor( conv )
		);
	};

	/**
	 * Where the visitor is and, on WooCommerce shops, their cart and
	 * orders. Loaded when the details open and refreshed with them.
	 */
	Panel.prototype.buildCustomer = function ( conv ) {
		var self = this;
		var section = h( 'section', { className: 'cc-info-shop', 'aria-live': 'polite' } );
		var cached = this.customerCache && this.customerCache.id === conv.id ? this.customerCache.data : null;

		function render( data ) {
			var nodes = [];

			if ( data.page ) {
				nodes.push( h( 'h3', {}, [ icon( 'eye' ), S.currentPage ] ) );
				nodes.push( h( 'a', { className: 'cc-shop-page', href: data.page.url, target: '_blank', rel: 'noopener noreferrer', dir: 'auto', text: data.page.title || decodeSafe( data.page.url ) } ) );
				if ( data.page.at ) {
					nodes.push( h( 'p', { className: 'cc-hint', text: fmt.ago( new Date( data.page.at ) ) } ) );
				}
			}

			if ( data.shop ) {
				var cart = data.cart;
				nodes.push( h( 'h3', {}, [ icon( 'cart' ), cart && cart.count ? S.cartCount.replace( '%s', fmt.number( cart.count ) ) : S.cart ] ) );
				if ( cart && cart.items.length ) {
					nodes.push( h( 'ul', { className: 'cc-shop-list' }, cart.items.map( function ( item ) {
						return h( 'li', {}, [
							item.url ? h( 'a', { href: item.url, target: '_blank', rel: 'noopener noreferrer', text: item.name } ) : h( 'span', { text: item.name } ),
							h( 'span', { className: 'cc-shop-qty', text: '× ' + fmt.number( item.quantity ) } ),
							h( 'span', { className: 'cc-shop-price', text: item.total } )
						] );
					} ) ) );
					nodes.push( h( 'p', { className: 'cc-shop-total' }, [ h( 'span', { text: S.cartTotal } ), h( 'strong', { text: cart.total } ) ] ) );
				} else {
					nodes.push( h( 'p', { className: 'cc-muted', text: S.cartEmpty } ) );
				}

				if ( data.can_orders && data.orders ) {
					nodes.push( h( 'h3', {}, [ icon( 'clipboard' ), S.orders ] ) );
					if ( data.orders.count ) {
						nodes.push( h( 'p', { className: 'cc-hint', text: S.ordersSummary.replace( '%1$s', fmt.number( data.orders.count ) ).replace( '%2$s', data.orders.spent ) } ) );
						nodes.push( h( 'ul', { className: 'cc-shop-orders' }, data.orders.items.map( function ( order ) {
							var head = [
								h( 'strong', { text: '#' + order.number } ),
								h( 'span', { className: 'cc-order-status cc-order-status--' + order.status, text: order.status_label } ),
								h( 'span', { className: 'cc-shop-price', text: order.total } )
							];
							return h( 'li', {}, [
								order.url ? h( 'a', { className: 'cc-order-head', href: order.url, target: '_blank', rel: 'noopener noreferrer' }, head ) : h( 'div', { className: 'cc-order-head' }, head ),
								h( 'p', { className: 'cc-hint', text: ( order.date ? fmt.ago( new Date( order.date ) ) + ' · ' : '' ) + order.items.join( '، ' ) } )
							] );
						} ) ) );
					} else {
						nodes.push( h( 'p', { className: 'cc-muted', text: S.noOrders } ) );
					}
				}
			}

			section.hidden = ! nodes.length;
			section.replaceChildren.apply( section, nodes );
		}

		if ( cached ) {
			render( cached );
		} else {
			section.hidden = true;
		}

		api.customer( conv.id ).then( function ( data ) {
			self.customerCache = { id: conv.id, data: data };
			if ( self.state.conv && self.state.conv.id === conv.id ) {
				render( data );
			}
		} ).catch( function () {
			// The rest of the details still work.
		} );

		return section;
	};

	/**
	 * Tags of the open conversation: chips with a remove button, and a box
	 * to add more (Enter or comma), suggesting tags already in use.
	 */
	Panel.prototype.buildTagEditor = function ( conv ) {
		var self = this;
		var tags = ( conv.tags || [] ).slice();
		var chips = h( 'div', { className: 'cc-tags' } );
		var listId = 'cc-tag-suggestions';
		var input = h( 'input', { type: 'text', className: 'cc-input cc-tags__input', placeholder: S.addTag, 'aria-label': S.addTag, list: listId, maxlength: '40' } );
		var suggestions = h( 'datalist', { id: listId }, ( this.popularTags || [] ).map( function ( item ) {
			return h( 'option', { value: item.tag } );
		} ) );

		function save( next ) {
			api.setTags( conv.id, next ).then( function ( result ) {
				if ( self.state.conv && self.state.conv.id === conv.id ) {
					self.state.conv.tags = result.tags;
					tags = result.tags.slice();
					renderChips();
				}
				self.loadTags();
				self.loadList( true );
			} ).catch( function ( error ) {
				self.notify( error.message, true );
			} );
		}

		function renderChips() {
			chips.replaceChildren.apply( chips, tags.length ? tags.map( function ( tag ) {
				return h( 'span', { className: 'cc-tag cc-tag--removable' }, [
					h( 'span', { text: tag } ),
					h( 'button', {
						type: 'button',
						'aria-label': S.removeTag.replace( '%s', tag ),
						title: S.removeTag.replace( '%s', tag ),
						onClick: function () {
							save( tags.filter( function ( t ) {
								return t !== tag;
							} ) );
						}
					}, [ icon( 'close' ) ] )
				] );
			} ) : [ h( 'span', { className: 'cc-muted', text: S.noTags } ) ] );
		}

		function add() {
			var value = input.value.replace( /[,،]+$/, '' ).trim();
			if ( ! value ) {
				return;
			}
			input.value = '';
			if ( tags.indexOf( value.toLowerCase() ) === -1 ) {
				save( tags.concat( [ value ] ) );
			}
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( ( event.key === 'Enter' || event.key === ',' || event.key === '،' ) && ! event.isComposing ) {
				event.preventDefault();
				add();
			}
		} );
		input.addEventListener( 'change', function () {
			// Picking a suggestion from the list.
			if ( self.popularTags && self.popularTags.some( function ( item ) {
				return item.tag === input.value;
			} ) ) {
				add();
			}
		} );

		renderChips();

		return h( 'section', { className: 'cc-info-tags', 'aria-label': S.tags }, [
			h( 'h3', {}, [ icon( 'tag' ), S.tags ] ),
			chips,
			input,
			suggestions
		] );
	};

	/**
	 * Loads the most used tags, for suggestions and the inbox filter.
	 */
	Panel.prototype.loadTags = function () {
		var self = this;

		return api.tags().then( function ( tags ) {
			self.popularTags = tags;
			self.renderTagFilter();
		} ).catch( function () {} );
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
	/* -------------------------- saved replies -------------------------- */

	/**
	 * Loads the saved replies used by the composer picker.
	 */
	Panel.prototype.loadCanned = function () {
		var self = this;

		return api.canned().then( function ( items ) {
			self.canned = items;
		} ).catch( function () {
			// The picker then shows as empty; chatting still works.
		} );
	};

	/**
	 * Administrators' screen for the saved replies (#/canned).
	 */
	function CannedView( panel ) {
		this.panel = panel;
		this.el = panel.page;
		this.state = { loading: true, error: '', editing: null, busy: false, errors: {}, confirmId: 0 };
		this.loaded = false;
	}

	CannedView.prototype.show = function () {
		var self = this;

		if ( ! this.loaded ) {
			this.loaded = true;
			this.state.loading = true;
			this.panel.loadCanned().then( function () {
				self.state.loading = false;
				self.render();
			} );
		}
		this.render();
	};

	CannedView.prototype.render = function () {
		var self = this;
		var state = this.state;
		var items = this.panel.canned;

		if ( this.panel.view !== 'canned' ) {
			return;
		}

		var header = h( 'header', { className: 'cc-page-head' }, [
			h( 'div', {}, [
				h( 'h1', { text: S.canned } ),
				h( 'p', { text: S.cannedIntro } )
			] ),
			state.editing ? null : h( 'button', {
				type: 'button',
				className: 'cc-button',
				onClick: function () {
					self.edit( { id: 0, title: '', shortcut: '', body: '' } );
				}
			}, [ icon( 'plus' ), S.cannedAdd ] )
		] );

		var body;

		if ( state.loading ) {
			body = h( 'div', { className: 'cc-canned-list', 'aria-busy': 'true' }, [ 1, 2, 3 ].map( function () {
				return h( 'div', { className: 'cc-canned-item' }, [ h( 'span', { className: 'cc-skeleton', style: 'width:40%;height:14px' } ) ] );
			} ) );
		} else if ( ! items.length && ! state.editing ) {
			body = h( 'div', { className: 'cc-state' }, [
				icon( 'zap' ),
				h( 'h2', { text: S.cannedEmpty } ),
				h( 'p', { text: S.cannedEmptyHint } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button',
					onClick: function () {
						self.edit( { id: 0, title: '', shortcut: '', body: '' } );
					}
				}, [ icon( 'plus' ), S.cannedAdd ] )
			] );
		} else {
			body = h( 'div', { className: 'cc-canned-list' }, items.map( function ( item ) {
				return state.editing && state.editing.id === item.id ? self.buildEditor() : self.buildItem( item );
			} ) );
		}

		this.el.replaceChildren( h( 'div', { className: 'cc-page-inner' }, [
			header,
			h( 'p', { className: 'cc-hint cc-canned-tip', text: S.cannedTip } ),
			state.editing && ! state.editing.id ? this.buildEditor() : null,
			body
		] ) );

		var first = this.el.querySelector( '.cc-canned-editor input' );
		if ( first && this.focusEditor ) {
			this.focusEditor = false;
			first.focus();
		}
	};

	CannedView.prototype.edit = function ( item ) {
		this.state.editing = { id: item.id, title: item.title, shortcut: item.shortcut, body: item.body };
		this.state.errors = {};
		this.state.confirmId = 0;
		this.focusEditor = true;
		this.render();
	};

	CannedView.prototype.buildItem = function ( item ) {
		var self = this;
		var confirming = this.state.confirmId === item.id;

		return h( 'article', { className: 'cc-canned-item' }, [
			h( 'div', { className: 'cc-canned-item__text' }, [
				h( 'h2', {}, [
					item.title,
					item.shortcut ? h( 'code', { className: 'cc-canned-shortcut', dir: 'ltr', text: '/' + item.shortcut } ) : null
				] ),
				h( 'p', { text: item.body } )
			] ),
			h( 'div', { className: 'cc-canned-item__actions' }, confirming ? [
				h( 'span', { className: 'cc-hint', text: S.cannedDeleteConfirm } ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary cc-button--sm',
					text: S.cancel,
					onClick: function () {
						self.state.confirmId = 0;
						self.render();
					}
				} ),
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--danger cc-button--sm',
					text: S.delete,
					onClick: function () {
						self.remove( item );
					}
				} )
			] : [
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button',
					title: S.edit,
					'aria-label': S.edit + ' — ' + item.title,
					onClick: function () {
						self.edit( item );
					}
				}, [ icon( 'edit' ) ] ),
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button',
					title: S.delete,
					'aria-label': S.delete + ' — ' + item.title,
					onClick: function () {
						self.state.confirmId = item.id;
						self.render();
					}
				}, [ icon( 'trash' ) ] )
			] )
		] );
	};

	CannedView.prototype.buildEditor = function () {
		var self = this;
		var editing = this.state.editing;
		var errors = this.state.errors;

		function field( key, label, control, hint ) {
			control.addEventListener( 'input', function () {
				editing[ key ] = control.value;
			} );
			return h( 'div', { className: 'cc-field' }, [
				h( 'label', { className: 'cc-label', for: control.id, text: label } ),
				control,
				hint ? h( 'p', { className: 'cc-hint', text: hint } ) : null,
				errors[ key ] ? h( 'p', { className: 'cc-field-error', role: 'alert', text: errors[ key ] } ) : null
			] );
		}

		var title = h( 'input', { id: 'cc-canned-title', className: 'cc-input', type: 'text', value: editing.title, 'aria-invalid': errors.title ? 'true' : null } );
		var shortcut = h( 'input', { id: 'cc-canned-shortcut', className: 'cc-input', type: 'text', dir: 'ltr', placeholder: 'shipping', value: editing.shortcut, 'aria-invalid': errors.shortcut ? 'true' : null } );
		var text = h( 'textarea', { id: 'cc-canned-body', className: 'cc-input cc-textarea', rows: '5', value: editing.body, 'aria-invalid': errors.body ? 'true' : null } );

		return h( 'form', {
			className: 'cc-card cc-canned-editor',
			noValidate: true,
			onSubmit: function ( event ) {
				event.preventDefault();
				self.save();
			}
		}, [
			h( 'div', { className: 'cc-canned-editor__row' }, [
				field( 'title', S.cannedTitle, title ),
				field( 'shortcut', S.cannedShortcut, shortcut, S.cannedShortcutHint )
			] ),
			field( 'body', S.cannedBody, text, S.cannedBodyHint ),
			h( 'div', { className: 'cc-canned-editor__actions' }, [
				h( 'button', {
					type: 'button',
					className: 'cc-button cc-button--secondary',
					text: S.cancel,
					onClick: function () {
						self.state.editing = null;
						self.render();
					}
				} ),
				h( 'button', { type: 'submit', className: 'cc-button', disabled: this.state.busy, text: this.state.busy ? S.saving : S.save } )
			] )
		] );
	};

	CannedView.prototype.save = function () {
		var self = this;
		var editing = this.state.editing;

		this.state.busy = true;
		this.render();

		api.saveCanned( editing.id, { title: editing.title, shortcut: editing.shortcut, body: editing.body } ).then( function () {
			self.state.busy = false;
			self.state.editing = null;
			self.panel.notify( S.saved );
			return self.panel.loadCanned();
		} ).then( function () {
			self.render();
		} ).catch( function ( error ) {
			self.state.busy = false;
			self.state.errors = ( error.data && error.data.fields ) || {};
			if ( ! Object.keys( self.state.errors ).length ) {
				self.panel.notify( error.message, true );
			}
			self.render();
		} );
	};

	CannedView.prototype.remove = function ( item ) {
		var self = this;

		api.deleteCanned( item.id ).then( function () {
			self.state.confirmId = 0;
			self.panel.notify( S.cannedDeleted );
			return self.panel.loadCanned();
		} ).then( function () {
			self.render();
		} ).catch( function ( error ) {
			self.panel.notify( error.message, true );
		} );
	};

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
	 * Reports (site administrators only)
	 * ------------------------------------------------------------------ */

	var SVG_NS = 'http://www.w3.org/2000/svg';

	function svg( tag, attrs, children ) {
		var el = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			el.setAttribute( key, String( attrs[ key ] ) );
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				el.appendChild( child );
			}
		} );
		return el;
	}

	/**
	 * "45 s", "3 min", "1.5 h" in the panel's language.
	 */
	function formatDuration( seconds ) {
		var locale = /^fa/i.test( config.locale || '' ) ? 'fa-IR' : ( config.locale || undefined );
		var value;
		var unit;

		if ( seconds < 60 ) {
			value = seconds;
			unit = 'second';
		} else if ( seconds < 3600 ) {
			value = seconds / 60;
			value = value < 10 ? Math.round( value * 10 ) / 10 : Math.round( value );
			unit = 'minute';
		} else {
			value = Math.round( seconds / 360 ) / 10;
			unit = 'hour';
		}

		try {
			return new Intl.NumberFormat( locale, { style: 'unit', unit: unit, unitDisplay: 'short' } ).format( value );
		} catch ( e ) {
			return fmt.number( value ) + ' ' + unit.charAt( 0 );
		}
	}

	function ReportsView( panel ) {
		this.panel = panel;
		this.el = panel.page;
		this.range = 30;
		this.data = null;
		this.state = { loading: true, error: '' };
		this.ticket = 0;
	}

	ReportsView.prototype.show = function () {
		if ( ! this.data || this.data.range !== this.range ) {
			this.load();
			return;
		}
		this.render();
	};

	ReportsView.prototype.load = function () {
		var self = this;
		var ticket = ++this.ticket;

		this.state = { loading: true, error: '' };
		this.render();

		api.reports( this.range ).then( function ( data ) {
			if ( ticket !== self.ticket ) {
				return;
			}
			self.data = data;
			self.state = { loading: false, error: '' };
			self.render();
		} ).catch( function ( error ) {
			if ( ticket !== self.ticket ) {
				return;
			}
			self.state = { loading: false, error: error.message || S.reportsError };
			self.render();
		} );
	};

	ReportsView.prototype.render = function () {
		var self = this;

		if ( this.panel.view !== 'reports' ) {
			return;
		}

		var ranges = h( 'div', { className: 'cc-segmented', role: 'radiogroup', 'aria-label': S.reportsRange }, [ 7, 30, 90 ].map( function ( days ) {
			return h( 'button', {
				type: 'button',
				role: 'radio',
				className: 'cc-segmented__item' + ( days === self.range ? ' is-active' : '' ),
				'aria-checked': days === self.range ? 'true' : 'false',
				text: fmt.format( S.reportsDays, days ),
				onClick: function () {
					if ( days !== self.range ) {
						self.range = days;
						self.load();
					}
				}
			} );
		} ) );

		var header = h( 'header', { className: 'cc-page-head' }, [
			h( 'div', {}, [
				h( 'h1', { text: S.reports } ),
				h( 'p', { text: S.reportsIntro } )
			] ),
			ranges
		] );

		var body;
		var data = this.data;

		if ( this.state.loading ) {
			body = h( 'div', { className: 'cc-report', 'aria-busy': 'true' }, [
				h( 'div', { className: 'cc-kpis' }, [ 1, 2, 3, 4 ].map( function () {
					return h( 'div', { className: 'cc-card cc-kpi' }, [
						h( 'span', { className: 'cc-skeleton', style: 'display:block;width:50%;height:12px;margin-bottom:12px' } ),
						h( 'span', { className: 'cc-skeleton', style: 'display:block;width:40%;height:28px' } )
					] );
				} ) ),
				h( 'div', { className: 'cc-card' }, [ h( 'span', { className: 'cc-skeleton', style: 'display:block;width:100%;height:220px' } ) ] )
			] );
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
		} else if ( ! data.kpis.conversations.value ) {
			body = h( 'div', { className: 'cc-state' }, [
				icon( 'chart' ),
				h( 'h2', { text: S.reportsEmpty } ),
				h( 'p', { text: S.reportsEmptyHint } )
			] );
		} else {
			body = h( 'div', { className: 'cc-report' }, [
				this.buildKpis(),
				h( 'div', { className: 'cc-report__row cc-report__row--wide' }, [ this.buildDaily(), this.buildRatings() ] ),
				this.buildAgents(),
				h( 'div', { className: 'cc-report__row' }, [ this.buildHeatmap(), this.buildTags() ] ),
				this.buildComments()
			] );
		}

		this.el.replaceChildren( h( 'div', { className: 'cc-page-inner cc-page-inner--wide' }, [ header, body ] ) );
	};

	/**
	 * A change against the previous period, e.g. "▲ 12% vs. the previous 30 days".
	 *
	 * @param {string} kind "percent" (relative change), "points" (percentage
	 *                      points), "stars" (absolute) or "time" (lower is better).
	 */
	ReportsView.prototype.delta = function ( current, previous, kind ) {
		if ( current === null || previous === null || previous === undefined || ( kind === 'percent' && previous === 0 ) ) {
			return h( 'p', { className: 'cc-kpi__delta', text: S.reportsNoPrevious } );
		}

		var change = current - previous;
		var text;

		if ( kind === 'percent' ) {
			text = fmt.number( Math.round( Math.abs( change ) / previous * 100 ) ) + '٪';
		} else if ( kind === 'points' ) {
			text = fmt.number( Math.abs( change ) ) + '٪';
		} else if ( kind === 'stars' ) {
			text = fmt.number( Math.round( Math.abs( change ) * 100 ) / 100 );
		} else {
			text = formatDuration( Math.abs( change ) );
		}

		if ( ! /^fa/i.test( config.locale || '' ) ) {
			text = text.replace( '٪', '%' );
		}

		var better = kind === 'time' ? change < 0 : change > 0;
		var tone = change === 0 ? 'flat' : ( kind === 'percent' ? 'neutral' : ( better ? 'up' : 'down' ) );

		return h( 'p', { className: 'cc-kpi__delta cc-kpi__delta--' + tone }, [
			h( 'span', { className: 'cc-kpi__arrow', 'aria-hidden': 'true', text: change === 0 ? '–' : ( change > 0 ? '▲' : '▼' ) } ),
			h( 'span', { text: ( change === 0 ? S.reportsSame : text ) + ' ' + fmt.format( S.reportsVsPrevious, this.data.range ) } )
		] );
	};

	ReportsView.prototype.buildKpis = function () {
		var k = this.data.kpis;
		var satisfaction = k.satisfaction.value;

		function tile( iconName, label, value, sub, delta, hint ) {
			return h( 'div', { className: 'cc-card cc-kpi' }, [
				h( 'div', { className: 'cc-kpi__head' }, [ icon( iconName ), h( 'span', { text: label } ) ] ),
				h( 'p', { className: 'cc-kpi__value', text: value } ),
				sub ? h( 'p', { className: 'cc-kpi__sub', text: sub } ) : null,
				delta,
				hint ? h( 'p', { className: 'cc-kpi__hint', text: hint } ) : null
			] );
		}

		var percentSign = /^fa/i.test( config.locale || '' ) ? '٪' : '%';

		return h( 'div', { className: 'cc-kpis' }, [
			tile( 'message', S.kpiConversations, fmt.number( k.conversations.value ),
				S.kpiMessages.replace( '%1$s', fmt.number( k.messages.visitor ) ).replace( '%2$s', fmt.number( k.messages.agent ) ),
				this.delta( k.conversations.value, k.conversations.previous, 'percent' ) ),
			tile( 'check', S.kpiAnswered, k.answered.value === null ? '—' : fmt.number( k.answered.value ) + percentSign,
				k.answered.missed ? fmt.format( S.kpiMissed, k.answered.missed ) : S.kpiNoMissed,
				this.delta( k.answered.value, k.answered.previous, 'points' ) ),
			tile( 'clock', S.kpiFirstResponse, k.first_response.value === null ? '—' : formatDuration( k.first_response.value ),
				k.first_response.average === null ? '' : S.kpiAverage.replace( '%s', formatDuration( k.first_response.average ) ),
				this.delta( k.first_response.value, k.first_response.previous, 'time' ),
				S.kpiFirstResponseHint ),
			tile( 'star', S.kpiSatisfaction, satisfaction === null ? '—' : fmt.number( satisfaction.toFixed( 1 ) ) + ' / ' + fmt.number( 5 ),
				k.satisfaction.count ? fmt.format( S.kpiRatings, k.satisfaction.count ) + ' · ' + S.kpiSatisfied.replace( '%s', fmt.number( k.satisfaction.satisfied ) + percentSign ) : S.reportsNoRatings,
				this.delta( satisfaction, k.satisfaction.previous, 'stars' ) )
		] );
	};

	/**
	 * Conversations per day as bars; the answered part is filled.
	 */
	ReportsView.prototype.buildDaily = function () {
		var days = this.data.daily;
		var width = 640;
		var height = 220;
		var pad = { top: 12, right: 8, bottom: 26, left: 34 };
		var plotW = width - pad.left - pad.right;
		var plotH = height - pad.top - pad.bottom;
		var max = Math.max.apply( null, days.map( function ( d ) {
			return d.conversations;
		} ).concat( [ 1 ] ) );
		var step = Math.max( 1, Math.ceil( max / 4 ) );
		var top = step * Math.ceil( max / step );
		var slot = plotW / days.length;
		var barW = Math.max( 2, Math.min( 22, slot * 0.66 ) );
		var dayFormat = new Intl.DateTimeFormat( /^fa/i.test( config.locale || '' ) ? 'fa-IR-u-ca-persian' : config.locale, { day: 'numeric', month: 'short' } );
		var tooltip = h( 'div', { className: 'cc-chart__tip', hidden: true } );

		function y( value ) {
			return pad.top + plotH - ( value / top ) * plotH;
		}

		var grid = [];
		for ( var v = 0; v <= top; v += step ) {
			grid.push( svg( 'line', { x1: pad.left, x2: width - pad.right, y1: y( v ), y2: y( v ), class: v === 0 ? 'cc-chart__axis' : 'cc-chart__grid' } ) );
			grid.push( svg( 'text', { x: pad.left - 8, y: y( v ) + 4, 'text-anchor': 'end', class: 'cc-chart__label' }, [ document.createTextNode( fmt.number( v ) ) ] ) );
		}

		var every = Math.ceil( days.length / 7 );
		var bars = days.map( function ( d, i ) {
			var x = pad.left + slot * i + ( slot - barW ) / 2;
			var date = new Date( d.date + 'T12:00:00' );
			var label = dayFormat.format( date );
			var group = svg( 'g', { class: 'cc-chart__bar', tabindex: '0', role: 'img', 'aria-label': label + ': ' + fmt.format( S.chartConversationsCount, d.conversations ) + ', ' + fmt.format( S.chartAnsweredCount, d.answered ) }, [
				svg( 'rect', { x: x - ( slot - barW ) / 2, y: pad.top, width: slot, height: plotH, class: 'cc-chart__hit' } ),
				d.conversations ? svg( 'rect', { x: x, y: y( d.conversations ), width: barW, height: plotH - ( y( d.conversations ) - pad.top ), rx: Math.min( 3, barW / 3 ), class: 'cc-chart__total' } ) : null,
				d.answered ? svg( 'rect', { x: x, y: y( d.answered ), width: barW, height: plotH - ( y( d.answered ) - pad.top ), rx: Math.min( 3, barW / 3 ), class: 'cc-chart__answered' } ) : null,
				( days.length - 1 - i ) % every === 0 ? svg( 'text', { x: x + barW / 2, y: height - 6, 'text-anchor': 'middle', class: 'cc-chart__label' }, [ document.createTextNode( label ) ] ) : null
			] );

			function showTip() {
				tooltip.replaceChildren(
					h( 'strong', { text: label } ),
					h( 'span', { text: fmt.format( S.chartConversationsCount, d.conversations ) } ),
					h( 'span', { text: fmt.format( S.chartAnsweredCount, d.answered ) } )
				);
				tooltip.hidden = false;
				var box = chart.getBoundingClientRect();
				var cx = ( x + barW / 2 ) / width * box.width;
				tooltip.style.left = Math.max( 0, Math.min( box.width - 140, cx - 70 ) ) + 'px';
				tooltip.style.top = Math.max( 0, y( d.conversations ) / height * box.height - 70 ) + 'px';
			}

			group.addEventListener( 'mouseenter', showTip );
			group.addEventListener( 'focus', showTip );
			group.addEventListener( 'mouseleave', function () {
				tooltip.hidden = true;
			} );
			group.addEventListener( 'blur', function () {
				tooltip.hidden = true;
			} );
			return group;
		} );

		var chart = h( 'div', { className: 'cc-chart' }, [
			svg( 'svg', { viewBox: '0 0 ' + width + ' ' + height, class: 'cc-chart__svg', role: 'group', 'aria-label': S.chartDaily, direction: 'ltr' }, grid.concat( bars ) ),
			tooltip
		] );

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [
				h( 'h2', { text: S.chartDaily } ),
				h( 'div', { className: 'cc-legend' }, [
					h( 'span', { className: 'cc-legend__item' }, [ h( 'span', { className: 'cc-legend__swatch cc-legend__swatch--answered' } ), S.legendAnswered ] ),
					h( 'span', { className: 'cc-legend__item' }, [ h( 'span', { className: 'cc-legend__swatch cc-legend__swatch--total' } ), S.legendUnanswered ] )
				] )
			] ),
			chart
		] );
	};

	ReportsView.prototype.buildRatings = function () {
		var ratings = this.data.ratings;
		var total = [ 1, 2, 3, 4, 5 ].reduce( function ( sum, n ) {
			return sum + ratings[ n ];
		}, 0 );

		var content = total ? h( 'ul', { className: 'cc-hbars' }, [ 5, 4, 3, 2, 1 ].map( function ( n ) {
			var share = Math.round( ratings[ n ] / total * 100 );
			return h( 'li', { className: 'cc-hbar', 'aria-label': fmt.format( S.ratedStars, n ) + ': ' + fmt.number( ratings[ n ] ) }, [
				h( 'span', { className: 'cc-hbar__label cc-hbar__label--stars', 'aria-hidden': 'true' }, [ fmt.number( n ), icon( 'star' ) ] ),
				h( 'span', { className: 'cc-hbar__track' }, [ h( 'span', { className: 'cc-hbar__fill cc-hbar__fill--r' + n, style: 'width:' + share + '%' } ) ] ),
				h( 'span', { className: 'cc-hbar__value', text: fmt.number( ratings[ n ] ) } )
			] );
		} ) ) : h( 'p', { className: 'cc-report__empty', text: S.reportsNoRatings } );

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [ h( 'h2', { text: S.chartRatings } ) ] ),
			content
		] );
	};

	ReportsView.prototype.buildAgents = function () {
		var agents = this.data.agents;
		var rows = agents.rows;
		var maxConversations = Math.max.apply( null, rows.map( function ( row ) {
			return row.conversations;
		} ).concat( [ 1 ] ) );

		var table = rows.length ? h( 'div', { className: 'cc-table-wrap' }, [ h( 'table', { className: 'cc-table' }, [
			h( 'thead', {}, [ h( 'tr', {}, [ S.colAgent, S.colConversations, S.colReplies, S.colFirstResponse, S.colRating ].map( function ( label, i ) {
				return h( 'th', { scope: 'col', className: i ? 'is-num' : '', text: label } );
			} ) ) ] ),
			h( 'tbody', {}, rows.map( function ( row ) {
				return h( 'tr', {}, [
					h( 'th', { scope: 'row' }, [ h( 'span', { className: 'cc-table__agent' }, [ avatar( row.name, 'sm' ), h( 'span', { text: row.name } ) ] ) ] ),
					h( 'td', { className: 'is-num' }, [ h( 'span', { className: 'cc-table__count' }, [
						h( 'span', { className: 'cc-table__bar' }, [
							h( 'span', { className: 'cc-table__bar-fill', style: 'width:' + Math.round( row.conversations / maxConversations * 100 ) + '%' } )
						] ),
						h( 'span', { text: fmt.number( row.conversations ) } )
					] ) ] ),
					h( 'td', { className: 'is-num', text: fmt.number( row.replies ) } ),
					h( 'td', { className: 'is-num', text: row.first_response === null ? '—' : formatDuration( row.first_response ) } ),
					h( 'td', { className: 'is-num' }, [ row.rating === null ? '—' : h( 'span', { className: 'cc-table__rating' }, [ icon( 'star' ), fmt.number( row.rating.toFixed( 1 ) ), h( 'small', { text: '(' + fmt.number( row.rating_count ) + ')' } ) ] ) ] )
				] );
			} ) )
		] ) ] ) : h( 'p', { className: 'cc-report__empty', text: S.reportsNoAgents } );

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [ h( 'h2', { text: S.tableAgents } ) ] ),
			table,
			agents.unassigned ? h( 'p', { className: 'cc-hint', text: fmt.format( S.reportsUnassigned, agents.unassigned ) } ) : null
		] );
	};

	/**
	 * When conversations start, by weekday and hour.
	 */
	ReportsView.prototype.buildHeatmap = function () {
		var grid = this.data.heatmap;
		var max = 1;

		grid.forEach( function ( hours ) {
			hours.forEach( function ( n ) {
				max = Math.max( max, n );
			} );
		} );

		var hoursRow = h( 'div', { className: 'cc-heat__hours', 'aria-hidden': 'true' }, [ h( 'span' ) ].concat( Array.from( { length: 24 }, function ( _, hour ) {
			return h( 'span', { text: hour % 6 === 0 ? fmt.number( hour ) : '' } );
		} ) ) );

		var rows = weekOrder().map( function ( day ) {
			var hours = grid[ parseInt( day, 10 ) ];
			var name = dayName( day );
			return h( 'div', { className: 'cc-heat__row' }, [ h( 'span', { className: 'cc-heat__day', text: name } ) ].concat( hours.map( function ( n, hour ) {
				var level = n ? Math.max( 0.12, n / max ) : 0;
				return h( 'span', {
					className: 'cc-heat__cell' + ( n ? '' : ' is-empty' ),
					style: n ? '--level:' + level.toFixed( 2 ) : null,
					title: name + ' ' + fmt.time( new Date( 2024, 0, 7, hour, 0 ) ) + ' — ' + fmt.format( S.chartConversationsCount, n )
				} );
			} ) ) );
		} );

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [
				h( 'h2', { text: S.chartHours } ),
				h( 'p', { className: 'cc-hint', text: S.chartHoursHint } )
			] ),
			h( 'div', { className: 'cc-heat', role: 'img', 'aria-label': S.chartHours }, [ hoursRow ].concat( rows ) ),
			h( 'div', { className: 'cc-heat__scale', 'aria-hidden': 'true' }, [
				h( 'span', { text: S.heatLess } ),
				[ 0.15, 0.4, 0.7, 1 ].map( function ( level ) {
					return h( 'span', { className: 'cc-heat__cell', style: '--level:' + level } );
				} ),
				h( 'span', { text: S.heatMore } )
			].reduce( function ( all, item ) {
				return all.concat( item );
			}, [] ) )
		] );
	};

	ReportsView.prototype.buildTags = function () {
		var tags = this.data.tags;
		var max = tags.length ? tags[ 0 ].count : 1;

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [ h( 'h2', { text: S.chartTags } ) ] ),
			tags.length ? h( 'ul', { className: 'cc-hbars' }, tags.map( function ( tag ) {
				return h( 'li', { className: 'cc-hbar' }, [
					h( 'span', { className: 'cc-hbar__label', dir: 'auto', text: '#' + tag.tag } ),
					h( 'span', { className: 'cc-hbar__track' }, [ h( 'span', { className: 'cc-hbar__fill', style: 'width:' + Math.round( tag.count / max * 100 ) + '%' } ) ] ),
					h( 'span', { className: 'cc-hbar__value', text: fmt.number( tag.count ) } )
				] );
			} ) ) : h( 'p', { className: 'cc-report__empty', text: S.reportsNoTags } )
		] );
	};

	ReportsView.prototype.buildComments = function () {
		var comments = this.data.comments;

		if ( ! comments.length ) {
			return null;
		}

		return h( 'section', { className: 'cc-card cc-report__card' }, [
			h( 'div', { className: 'cc-report__head' }, [ h( 'h2', { text: S.reportsComments } ) ] ),
			h( 'ul', { className: 'cc-comments' }, comments.map( function ( item ) {
				return h( 'li', {}, [
					h( 'a', { className: 'cc-comment', href: '#/conversations/' + item.conversation_id }, [
						h( 'span', { className: 'cc-comment__stars cc-comment__stars--' + ( item.score >= 4 ? 'good' : item.score === 3 ? 'ok' : 'bad' ), 'aria-label': fmt.format( S.ratedStars, item.score ), text: '★★★★★'.slice( 0, item.score ) + '☆☆☆☆☆'.slice( 0, 5 - item.score ) } ),
						h( 'p', { className: 'cc-comment__text', text: item.comment } ),
						h( 'span', { className: 'cc-comment__meta', text: ( item.visitor || S.visitor ) + ( item.at ? ' · ' + fmt.ago( new Date( item.at ) ) : '' ) } )
					] )
				] );
			} ) )
		] );
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
		{ id: 'general', icon: 'power', keys: [ 'widget_enabled', 'widget_sound', 'visitor_cookie_days', 'delete_data_on_uninstall' ] },
		{ id: 'appearance', icon: 'palette', keys: [ 'primary_color', 'widget_position', 'widget_theme', 'widget_title', 'widget_subtitle' ] },
		{ id: 'form', icon: 'clipboard', keys: [ 'form_name', 'form_email', 'form_phone', 'form_intro' ] },
		{ id: 'display', icon: 'eye', keys: [ 'welcome_enabled', 'welcome_message', 'display_mode', 'display_targets', 'display_ids' ] },
		{ id: 'hours', icon: 'clock', keys: [ 'hours_enabled', 'hours', 'offline_mode', 'offline_no_agents', 'offline_message' ] },
		{ id: 'notifications', icon: 'bell', keys: [ 'notify_email_enabled', 'notify_email', 'bot_notify', 'bale_enabled', 'bale_token', 'bale_chat_id', 'telegram_enabled', 'telegram_token', 'telegram_chat_id', 'telegram_api_url', 'transcript_enabled' ] },
		{ id: 'sales', icon: 'message', keys: [ 'proactive', 'rating_enabled' ] },
		{ id: 'contact', icon: 'help', keys: [ 'channels_enabled', 'channels', 'faq_enabled', 'faq' ] },
		{ id: 'ai', icon: 'sparkles', keys: [ 'ai_provider', 'ai_api_key', 'ai_base_url', 'ai_model', 'ai_bot_enabled', 'ai_bot_mode', 'ai_bot_name', 'ai_instructions', 'ai_knowledge_ids', 'ai_max_replies', 'ai_suggest_enabled', 'ai_daily_limit' ] },
		{ id: 'sms', icon: 'phone', keys: [ 'sms_enabled', 'sms_provider', 'sms_api_key', 'sms_username', 'sms_password', 'sms_sender', 'sms_message', 'sms_delay' ] },
		{ id: 'limits', icon: 'gauge', keys: [ 'spam_protection', 'limit_messages_visitor', 'limit_messages_ip', 'limit_starts_visitor', 'limit_starts_ip', 'message_max_length', 'attachments_enabled', 'attachment_max_mb' ] },
		{ id: 'security', icon: 'shield', keys: [ 'proxy_mode', 'proxy_header', 'trusted_proxies' ] }
	];

	var NUMBER_SETTINGS = [ 'ai_max_replies', 'ai_daily_limit', 'sms_delay', 'visitor_cookie_days', 'message_max_length', 'limit_messages_visitor', 'limit_messages_ip', 'limit_starts_visitor', 'limit_starts_ip', 'attachment_max_mb' ];

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
			// Copies, so edits never touch the saved values (the week is nested).
			draft[ key ] = value && typeof value === 'object' ? JSON.parse( JSON.stringify( value ) ) : value;
		} );

		if ( id === 'display' ) {
			// Items that were deleted since they were chosen are dropped on save.
			this.items = this.data.items.slice();
		}
		if ( id === 'ai' ) {
			this.knowledge = this.data.knowledge.slice();
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
		if ( id === 'sales' ) {
			payload.proactive = draft.proactive.map( function ( rule ) {
				var delay = String( rule.delay ).trim();
				return Object.assign( {}, rule, {
					url: rule.target === 'url' ? String( rule.url ).trim() : '',
					message: String( rule.message ).trim(),
					delay: /^\d+$/.test( delay ) ? parseInt( delay, 10 ) : delay
				} );
			} );
		}
		if ( id === 'contact' ) {
			payload.channels = draft.channels.map( function ( channel ) {
				return { type: channel.type, value: String( channel.value ).trim(), label: String( channel.label ).trim() };
			} );
			payload.faq = draft.faq.map( function ( item ) {
				return { id: item.id, question: String( item.question ).trim(), answer: String( item.answer ).trim() };
			} );
		}
		if ( id === 'ai' ) {
			[ 'ai_api_key', 'ai_base_url', 'ai_model', 'ai_bot_name', 'ai_instructions' ].forEach( function ( key ) {
				payload[ key ] = String( draft[ key ] ).trim();
			} );
			payload.ai_knowledge_ids = this.knowledge.map( function ( item ) {
				return item.id;
			} );
		}
		if ( id === 'sms' ) {
			[ 'sms_api_key', 'sms_username', 'sms_sender', 'sms_message' ].forEach( function ( key ) {
				payload[ key ] = String( draft[ key ] ).trim();
			} );
		}
		if ( id === 'notifications' ) {
			[ 'notify_email', 'bale_token', 'bale_chat_id', 'telegram_token', 'telegram_chat_id', 'telegram_api_url' ].forEach( function ( key ) {
				payload[ key ] = String( draft[ key ] ).trim();
			} );
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
			if ( key === 'ai_knowledge_ids' ) {
				stored = self.data.knowledge.map( function ( item ) {
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
			hours: S.sectionHours,
			notifications: S.sectionNotifications,
			limits: S.sectionLimits,
			sales: S.sectionSales,
			contact: S.sectionContact,
			sms: S.sectionSms,
			ai: S.sectionAi,
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
		} else if ( this.section === 'hours' ) {
			children = [ this.buildHours() ];
		} else if ( this.section === 'notifications' ) {
			children = [ this.buildNotifications() ];
		} else if ( this.section === 'sales' ) {
			children = [ this.buildSales() ];
		} else if ( this.section === 'contact' ) {
			children = [ this.buildChannels(), this.buildFaq() ];
		} else if ( this.section === 'sms' ) {
			children = [ this.buildSms() ];
		} else if ( this.section === 'ai' ) {
			children = [ this.buildAi() ];
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
		el.style.setProperty( '--chatchi-primary-soft-light', tokens.primarySoft );
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

		// Light, dark or the visitor's device.
		var themes = h( 'div', { className: 'cc-radios cc-radios--row', role: 'radiogroup', 'aria-labelledby': 'cc-theme-label' }, [
			[ 'light', S.themeLight, 'sun' ],
			[ 'dark', S.themeDark, 'moon' ],
			[ 'auto', S.themeAutoVisitor, 'monitor' ]
		].map( function ( option ) {
			var id = 'cc-theme-' + option[ 0 ];
			var input = h( 'input', { id: id, type: 'radio', name: 'cc-theme', value: option[ 0 ], checked: draft.widget_theme === option[ 0 ] } );

			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					draft.widget_theme = option[ 0 ];
					self.updatePreview();
				}
			} );

			return h( 'label', { className: 'cc-radio cc-theme-option', for: id }, [ input, icon( option[ 2 ] ), h( 'span', { text: option[ 1 ] } ) ] );
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
			this.fieldError( 'widget_position' ),
			h( 'span', { className: 'cc-label', id: 'cc-theme-label', text: S.widgetTheme } ),
			themes,
			h( 'p', { className: 'cc-hint', text: S.widgetThemeHint } ),
			this.fieldError( 'widget_theme' )
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
			h( 'span', { className: 'cc-preview__launcher' }, [ icon( 'launcher' ) ] )
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
		preview.frame.classList.toggle( 'chatchi--dark', draft.widget_theme === 'dark' );
		preview.frame.classList.toggle( 'chatchi--auto', draft.widget_theme === 'auto' );
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
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-sound-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-sound-title', text: S.soundTitle } ),
						h( 'p', { className: 'cc-hint', text: S.soundIntro } )
					] ),
					this.buildSwitch( 'cc-widget-sound', S.soundOn, draft.widget_sound, function ( on ) {
						draft.widget_sound = on;
					} )
				] ),
				this.fieldError( 'widget_sound' )
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
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-files-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-files-title', text: S.filesTitle } ),
						h( 'p', { className: 'cc-hint', text: S.filesIntro } )
					] ),
					this.buildSwitch( 'cc-attachments-enabled', S.filesOn, draft.attachments_enabled, function ( on ) {
						draft.attachments_enabled = on;
					} )
				] ),
				this.numberField( 'attachment_max_mb', S.filesMaxSize, S.megabytes, S.filesServerLimit.replace( '%s', fmt.number( this.data.limits.upload_max_mb ) ) ),
				this.fieldError( 'attachments_enabled' )
			] )
		] );
	};

	/* -------------------------- business hours ------------------------- */

	/**
	 * Days in the order the site's week starts: Saturday first for Persian
	 * sites, as in the Iranian calendar; otherwise Monday first.
	 */
	function weekOrder() {
		return /^fa/.test( config.locale || '' ) ? [ '6', '0', '1', '2', '3', '4', '5' ] : [ '1', '2', '3', '4', '5', '6', '0' ];
	}

	function dayName( day ) {
		// 7 January 2024 was a Sunday (day "0").
		var date = new Date( 2024, 0, 7 + parseInt( day, 10 ), 12 );
		return new Intl.DateTimeFormat( config.locale || 'fa-IR', { weekday: 'long' } ).format( date );
	}

	SettingsView.prototype.buildHours = function () {
		var self = this;
		var draft = this.draft;
		var limit = this.data.limits.offline_message;

		var rows = weekOrder().map( function ( day ) {
			var entry = draft.hours[ day ];
			var id = 'cc-hours-' + day;
			var on = h( 'input', { id: id, type: 'checkbox', checked: entry.on } );
			var from = h( 'input', { type: 'time', className: 'cc-input cc-input--time', value: entry.from, step: '300', 'aria-label': dayName( day ) + ' — ' + S.hoursFrom, disabled: ! entry.on } );
			var to = h( 'input', { type: 'time', className: 'cc-input cc-input--time', value: entry.to === '24:00' ? '23:59' : entry.to, step: '300', 'aria-label': dayName( day ) + ' — ' + S.hoursTo, disabled: ! entry.on } );
			var row = h( 'div', { className: 'cc-hours__row' + ( entry.on ? '' : ' is-closed' ) } );

			on.addEventListener( 'change', function () {
				entry.on = on.checked;
				from.disabled = to.disabled = ! on.checked;
				row.classList.toggle( 'is-closed', ! on.checked );
				self.clearError( 'hours' );
			} );
			from.addEventListener( 'input', function () {
				entry.from = from.value;
				self.clearError( 'hours' );
			} );
			to.addEventListener( 'input', function () {
				entry.to = to.value;
				self.clearError( 'hours' );
			} );

			row.append(
				h( 'label', { className: 'cc-hours__day', for: id }, [ on, h( 'span', { text: dayName( day ) } ) ] ),
				h( 'span', { className: 'cc-hours__closed', text: S.hoursClosed } ),
				h( 'span', { className: 'cc-hours__times' }, [ from, h( 'span', { text: S.hoursTo } ), to ] )
			);

			return row;
		} );

		var week = h( 'div', { className: 'cc-hours' + ( draft.hours_enabled ? '' : ' is-off' ) }, rows );

		var modes = [ [ 'message', S.offlineModeMessage, S.offlineModeMessageHint ], [ 'chat', S.offlineModeChat, S.offlineModeChatHint ] ];
		var modeGroup = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-offline-mode-label' }, modes.map( function ( mode ) {
			var input = h( 'input', { id: 'cc-offline-mode-' + mode[ 0 ], type: 'radio', name: 'cc-offline-mode', value: mode[ 0 ], checked: draft.offline_mode === mode[ 0 ] } );
			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					draft.offline_mode = mode[ 0 ];
				}
			} );
			return h( 'label', { className: 'cc-radio cc-radio--rich', for: 'cc-offline-mode-' + mode[ 0 ] }, [
				input,
				h( 'span', {}, [ h( 'strong', { text: mode[ 1 ] } ), h( 'small', { text: mode[ 2 ] } ) ] )
			] );
		} ) );

		var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
		var message = h( 'textarea', { id: 'cc-offline-message', className: 'cc-input cc-textarea', rows: '3', placeholder: this.data.defaults.offline_message } );
		message.value = draft.offline_message;

		function count() {
			var used = Array.from( message.value ).length;
			counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limit );
			counter.classList.toggle( 'is-over', used > limit );
		}

		message.addEventListener( 'input', function () {
			draft.offline_message = message.value;
			count();
			self.clearError( 'offline_message' );
		} );
		count();

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-hours-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-hours-title', text: S.hoursTitle } ),
						h( 'p', { className: 'cc-hint', text: S.hoursIntro } )
					] ),
					this.buildSwitch( 'cc-hours-enabled', S.hoursOn, draft.hours_enabled, function ( on ) {
						draft.hours_enabled = on;
						week.classList.toggle( 'is-off', ! on );
					} )
				] ),
				week,
				h( 'p', { className: 'cc-hint', text: S.hoursTimezone.replace( '%s', this.data.timezone ) } ),
				this.fieldError( 'hours' )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-offline-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-offline-title', text: S.offlineTitle } ),
					h( 'p', { className: 'cc-hint', text: S.offlineIntro } )
				] ),
				h( 'span', { id: 'cc-offline-mode-label', className: 'cc-label', text: S.offlineModeLabel } ),
				modeGroup,
				this.buildSwitch( 'cc-offline-no-agents', S.offlineNoAgents, draft.offline_no_agents, function ( on ) {
					draft.offline_no_agents = on;
				} ),
				h( 'label', { className: 'cc-label', for: 'cc-offline-message', text: S.offlineMessage } ),
				message,
				h( 'div', { className: 'cc-settings__row' }, [
					h( 'p', { className: 'cc-hint', text: S.offlineMessageHint } ),
					counter
				] ),
				this.fieldError( 'offline_mode' ),
				this.fieldError( 'offline_message' )
			] )
		] );
	};

	/* -------------------------- notifications -------------------------- */

	SettingsView.prototype.buildNotifications = function () {
		var self = this;
		var draft = this.draft;

		var email = h( 'input', { id: 'cc-notify-email', className: 'cc-input', type: 'email', dir: 'ltr', placeholder: this.data.defaults.notify_email, disabled: ! draft.notify_email_enabled } );
		email.value = draft.notify_email;
		email.addEventListener( 'input', function () {
			draft.notify_email = email.value;
			self.clearError( 'notify_email' );
		} );

		var whens = [ [ 'new', S.botNotifyNew, S.botNotifyNewHint ], [ 'offline', S.botNotifyOffline, S.botNotifyOfflineHint ] ];
		var when = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-bot-when-title' }, whens.map( function ( option ) {
			var input = h( 'input', { id: 'cc-bot-notify-' + option[ 0 ], type: 'radio', name: 'cc-bot-notify', value: option[ 0 ], checked: draft.bot_notify === option[ 0 ] } );
			input.addEventListener( 'change', function () {
				if ( input.checked ) {
					draft.bot_notify = option[ 0 ];
				}
			} );
			return h( 'label', { className: 'cc-radio cc-radio--rich', for: input.id }, [ input, h( 'span', {}, [ h( 'strong', { text: option[ 1 ] } ), h( 'small', { text: option[ 2 ] } ) ] ) ] );
		} ) );

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-notify-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-notify-title', text: S.notifyTitle } ),
						h( 'p', { className: 'cc-hint', text: S.notifyIntro } )
					] ),
					this.buildSwitch( 'cc-notify-enabled', S.notifyOn, draft.notify_email_enabled, function ( on ) {
						draft.notify_email_enabled = on;
						email.disabled = ! on;
					} )
				] ),
				h( 'label', { className: 'cc-label', for: 'cc-notify-email', text: S.notifyEmail } ),
				email,
				h( 'p', { className: 'cc-hint', text: S.notifyEmailHint } ),
				this.fieldError( 'notify_email' )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-bot-when-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-bot-when-title', text: S.botWhenTitle } ),
					h( 'p', { className: 'cc-hint', text: S.botWhenIntro } )
				] ),
				when,
				this.fieldError( 'bot_notify' )
			] ),
			this.buildBot( 'bale', S.baleTitle, S.baleHelp ),
			this.buildBot( 'telegram', S.telegramTitle, S.telegramHelp ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-transcript-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-transcript-title', text: S.transcriptTitle } ),
						h( 'p', { className: 'cc-hint', text: S.transcriptIntro } )
					] ),
					this.buildSwitch( 'cc-transcript-enabled', S.transcriptOn, draft.transcript_enabled, function ( on ) {
						draft.transcript_enabled = on;
					} )
				] ),
				this.fieldError( 'transcript_enabled' )
			] )
		] );
	};

	/**
	 * One messenger bot: switch, token, chat ID and "send a test".
	 *
	 * @param {string} service "bale" or "telegram".
	 */
	SettingsView.prototype.buildBot = function ( service, title, help ) {
		var self = this;
		var draft = this.draft;
		var prefix = 'cc-' + service;
		var result = h( 'p', { className: 'cc-bot-result', role: 'status', hidden: true } );

		function input( key, label, attrs ) {
			var field = h( 'input', Object.assign( { id: prefix + '-' + key.replace( /_/g, '-' ), className: 'cc-input', type: 'text', dir: 'ltr', autocomplete: 'off', spellcheck: 'false' }, attrs || {} ) );
			field.value = draft[ service + '_' + key ];
			field.addEventListener( 'input', function () {
				draft[ service + '_' + key ] = field.value;
				self.clearError( service + '_' + key );
				result.hidden = true;
			} );
			return h( 'div', { className: 'cc-field' }, [
				h( 'label', { className: 'cc-label', for: field.id, text: label } ),
				field,
				self.fieldError( service + '_' + key )
			] );
		}

		var test = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary',
			text: S.botTest,
			onClick: function () {
				test.disabled = true;
				result.hidden = true;
				api.botTest( {
					service: service,
					token: String( draft[ service + '_token' ] ).trim(),
					chat_id: String( draft[ service + '_chat_id' ] ).trim(),
					api_url: service === 'telegram' ? String( draft.telegram_api_url ).trim() : ''
				} ).then( function () {
					result.textContent = S.botTestSent;
					result.className = 'cc-bot-result is-ok';
				} ).catch( function ( error ) {
					var fields = ( error.data && error.data.fields ) || {};
					var first = Object.keys( fields )[ 0 ];
					result.textContent = first ? fields[ first ] : error.message;
					result.className = 'cc-bot-result is-error';
				} ).then( function () {
					result.hidden = false;
					test.disabled = false;
				} );
			}
		} );

		var fields = [
			input( 'token', S.botToken, { type: 'password', placeholder: '123456789:AAH…' } ),
			input( 'chat_id', S.botChatId, { placeholder: '123456789 / @channel' } )
		];

		if ( service === 'telegram' ) {
			fields.push( input( 'api_url', S.telegramApiUrl, { type: 'url', placeholder: 'https://api.telegram.org' } ) );
			fields.push( h( 'p', { className: 'cc-hint', text: S.telegramApiUrlHint } ) );
		}

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': prefix + '-title' }, [
			h( 'div', { className: 'cc-settings__head' }, [
				h( 'div', {}, [
					h( 'h2', { id: prefix + '-title', text: title } ),
					h( 'p', { className: 'cc-hint cc-hint--pre', text: help } )
				] ),
				this.buildSwitch( prefix + '-enabled', S.botOn, draft[ service + '_enabled' ], function ( on ) {
					draft[ service + '_enabled' ] = on;
				} )
			] ),
			h( 'div', { className: 'cc-bot-fields' }, fields ),
			h( 'div', { className: 'cc-bot-test' }, [ test, result ] ),
			this.fieldError( service + '_enabled' )
		] );
	};

	/**
	 * Sales: chat invitations shown on chosen pages, and the rating asked
	 * for when a chat ends.
	 */
	SettingsView.prototype.buildSales = function () {
		var self = this;
		var draft = this.draft;
		var max = this.data.limits.proactive;
		var list = h( 'div', { className: 'cc-invites' } );
		var add = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary',
			onClick: function () {
				draft.proactive.push( { id: '', enabled: true, target: 'product', url: '', delay: 20, message: '', frequency: 'session' } );
				self.clearError( 'proactive' );
				fill();
				var fields = list.querySelectorAll( 'textarea' );
				if ( fields.length ) {
					fields[ fields.length - 1 ].focus();
				}
			}
		}, [ icon( 'plus' ), h( 'span', { text: S.inviteAdd } ) ] );

		function fill() {
			list.replaceChildren.apply( list, draft.proactive.length ? draft.proactive.map( function ( rule, index ) {
				return self.buildInvite( rule, index, function () {
					draft.proactive.splice( index, 1 );
					self.clearError( 'proactive' );
					fill();
					self.syncNav();
				} );
			} ) : [ h( 'p', { className: 'cc-invites__empty', text: S.inviteEmpty } ) ] );
			add.hidden = draft.proactive.length >= max;
		}

		fill();

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-invites-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-invites-title', text: S.invitesTitle } ),
					h( 'p', { className: 'cc-hint', text: S.invitesIntro } )
				] ),
				list,
				this.fieldError( 'proactive' ),
				h( 'div', {}, [ add ] )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-rating-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-rating-title', text: S.ratingTitle } ),
						h( 'p', { className: 'cc-hint', text: S.ratingIntro } )
					] ),
					this.buildSwitch( 'cc-rating-enabled', S.ratingOn, draft.rating_enabled, function ( on ) {
						draft.rating_enabled = on;
					} )
				] ),
				this.fieldError( 'rating_enabled' )
			] )
		] );
	};

	/**
	 * One invitation in the editor.
	 */
	SettingsView.prototype.buildInvite = function ( rule, index, onRemove ) {
		var self = this;
		var limit = this.data.limits.proactive_text;
		var prefix = 'cc-invite-' + index;
		var targets = [ [ 'all', S.inviteAll ], [ 'home', S.inviteHome ], [ 'product', S.inviteProduct ], [ 'shop', S.inviteShop ], [ 'cart', S.inviteCart ], [ 'checkout', S.inviteCheckout ], [ 'url', S.inviteUrl ] ];
		var frequencies = [ [ 'session', S.inviteOncePerVisit ], [ 'day', S.inviteOncePerDay ], [ 'always', S.inviteEveryPage ] ];

		function changed() {
			self.clearError( 'proactive' );
		}

		function select( id, options, value, onChange ) {
			var field = h( 'select', { id: id, className: 'cc-input cc-select' }, options.map( function ( option ) {
				return h( 'option', { value: option[ 0 ], text: option[ 1 ], selected: option[ 0 ] === value } );
			} ) );
			field.addEventListener( 'change', function () {
				onChange( field.value );
				changed();
			} );
			return field;
		}

		var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
		var message = h( 'textarea', { id: prefix + '-message', className: 'cc-input cc-textarea', rows: '2', placeholder: S.invitePlaceholder } );
		message.value = rule.message;

		function count() {
			var used = Array.from( message.value ).length;
			counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limit );
			counter.classList.toggle( 'is-over', used > limit );
		}

		message.addEventListener( 'input', function () {
			rule.message = message.value;
			count();
			changed();
		} );
		count();

		var url = h( 'input', { id: prefix + '-url', className: 'cc-input', type: 'text', dir: 'ltr', placeholder: '/product/shoes', 'aria-label': S.inviteUrlLabel, hidden: rule.target !== 'url' } );
		url.value = rule.url;
		url.addEventListener( 'input', function () {
			rule.url = url.value;
			changed();
		} );

		var delay = h( 'input', { id: prefix + '-delay', className: 'cc-input cc-input--number', type: 'number', min: '0', max: '600', step: '1', inputMode: 'numeric', dir: 'ltr' } );
		delay.value = String( rule.delay );
		delay.addEventListener( 'input', function () {
			rule.delay = delay.value;
			changed();
		} );

		var target = select( prefix + '-target', targets, rule.target, function ( value ) {
			rule.target = value;
			url.hidden = value !== 'url';
			if ( value === 'url' ) {
				url.focus();
			}
		} );

		var enabled = this.buildSwitch( prefix + '-enabled', S.inviteOn, rule.enabled, function ( on ) {
			rule.enabled = on;
			item.classList.toggle( 'is-off', ! on );
		} );

		var item = h( 'div', { className: 'cc-invite' + ( rule.enabled ? '' : ' is-off' ) }, [
			h( 'div', { className: 'cc-invite__head' }, [
				h( 'strong', { text: String( S.inviteNumber ).replace( '%s', fmt.number( index + 1 ) ) } ),
				enabled,
				h( 'button', {
					type: 'button',
					className: 'cc-icon-button',
					title: S.inviteRemove,
					'aria-label': S.inviteRemove,
					onClick: onRemove
				}, [ icon( 'trash' ) ] )
			] ),
			h( 'div', { className: 'cc-field' }, [
				h( 'div', { className: 'cc-label-row' }, [ h( 'label', { className: 'cc-label', for: message.id, text: S.inviteMessage } ), counter ] ),
				message
			] ),
			h( 'div', { className: 'cc-invite__grid' }, [
				h( 'div', { className: 'cc-field' }, [
					h( 'label', { className: 'cc-label', for: target.id, text: S.inviteWhere } ),
					target,
					url
				] ),
				h( 'div', { className: 'cc-field' }, [
					h( 'label', { className: 'cc-label', for: delay.id, text: S.inviteDelay } ),
					delay
				] ),
				h( 'div', { className: 'cc-field' }, [
					h( 'label', { className: 'cc-label', for: prefix + '-frequency', text: S.inviteFrequency } ),
					select( prefix + '-frequency', frequencies, rule.frequency, function ( value ) {
						rule.frequency = value;
					} )
				] )
			] )
		] );

		return item;
	};

	/**
	 * A simple list editor: rows built by buildRow, an "add" button, and
	 * removing / moving rows. Rows are the draft's own objects.
	 */
	SettingsView.prototype.listEditor = function ( key, max, addLabel, emptyLabel, blank, buildRow ) {
		var self = this;
		var draft = this.draft;
		var list = h( 'div', { className: 'cc-invites' } );
		var add = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary',
			onClick: function () {
				draft[ key ].push( blank() );
				self.clearError( key );
				fill();
				var fields = list.querySelectorAll( '.cc-invite' );
				var last = fields[ fields.length - 1 ];
				var focusable = last && last.querySelector( 'input, textarea, select' );
				if ( focusable ) {
					focusable.focus();
				}
				self.syncNav();
			}
		}, [ icon( 'plus' ), h( 'span', { text: addLabel } ) ] );

		function fill() {
			var rows = draft[ key ];
			list.replaceChildren.apply( list, rows.length ? rows.map( function ( row, index ) {
				var actions = [
					index > 0 ? h( 'button', {
						type: 'button',
						className: 'cc-icon-button cc-move-up',
						title: S.moveUp,
						'aria-label': S.moveUp,
						onClick: function () {
							rows.splice( index - 1, 0, rows.splice( index, 1 )[ 0 ] );
							fill();
							self.syncNav();
						}
					}, [ icon( 'chevron' ) ] ) : null,
					h( 'button', {
						type: 'button',
						className: 'cc-icon-button',
						title: S.remove,
						'aria-label': S.remove,
						onClick: function () {
							rows.splice( index, 1 );
							self.clearError( key );
							fill();
							self.syncNav();
						}
					}, [ icon( 'trash' ) ] )
				];
				return buildRow( row, index, actions, function () {
					self.clearError( key );
				} );
			} ) : [ h( 'p', { className: 'cc-invites__empty', text: emptyLabel } ) ] );
			add.hidden = rows.length >= max;
		}

		fill();

		return { list: list, add: add };
	};

	SettingsView.prototype.buildChannels = function () {
		var self = this;
		var draft = this.draft;
		var limit = this.data.limits.channel_label;
		var types = [ [ 'whatsapp', S.channelWhatsapp ], [ 'telegram', S.channelTelegram ], [ 'bale', S.channelBale ], [ 'phone', S.channelPhone ] ];
		var placeholders = { whatsapp: '09121234567', telegram: '@mystore', bale: '@mystore', phone: '02112345678' };

		var editor = this.listEditor( 'channels', this.data.limits.channels, S.channelAdd, S.channelEmpty, function () {
			return { type: 'whatsapp', value: '', label: '' };
		}, function ( channel, index, actions, changed ) {
			var prefix = 'cc-channel-' + index;
			var value = h( 'input', { id: prefix + '-value', className: 'cc-input', type: 'text', dir: 'ltr', autocomplete: 'off', placeholder: placeholders[ channel.type ] } );
			value.value = channel.value;
			value.addEventListener( 'input', function () {
				channel.value = value.value;
				changed();
			} );

			var type = h( 'select', { id: prefix + '-type', className: 'cc-input cc-select' }, types.map( function ( option ) {
				return h( 'option', { value: option[ 0 ], text: option[ 1 ], selected: option[ 0 ] === channel.type } );
			} ) );
			type.addEventListener( 'change', function () {
				channel.type = type.value;
				value.placeholder = placeholders[ channel.type ];
				changed();
			} );

			var label = h( 'input', { id: prefix + '-label', className: 'cc-input', type: 'text', placeholder: S.channelLabelPlaceholder } );
			label.value = channel.label;
			label.addEventListener( 'input', function () {
				channel.label = label.value;
				changed();
			} );

			return h( 'div', { className: 'cc-invite' }, [
				h( 'div', { className: 'cc-invite__head' }, [ h( 'strong', { text: String( S.channelNumber ).replace( '%s', fmt.number( index + 1 ) ) } ) ].concat( actions ) ),
				h( 'div', { className: 'cc-invite__grid cc-invite__grid--channel' }, [
					h( 'div', { className: 'cc-field' }, [ h( 'label', { className: 'cc-label', for: type.id, text: S.channelType } ), type ] ),
					h( 'div', { className: 'cc-field' }, [ h( 'label', { className: 'cc-label', for: value.id, text: S.channelValue } ), value ] ),
					h( 'div', { className: 'cc-field' }, [ h( 'label', { className: 'cc-label', for: label.id, text: fmt.format( S.channelLabel, limit ) } ), label ] )
				] )
			] );
		} );

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-channels-title' }, [
			h( 'div', { className: 'cc-settings__head' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-channels-title', text: S.channelsTitle } ),
					h( 'p', { className: 'cc-hint', text: S.channelsIntro } )
				] ),
				this.buildSwitch( 'cc-channels-enabled', S.channelsOn, draft.channels_enabled, function ( on ) {
					draft.channels_enabled = on;
					self.clearError( 'channels_enabled' );
				} )
			] ),
			editor.list,
			this.fieldError( 'channels' ),
			this.fieldError( 'channels_enabled' ),
			h( 'div', {}, [ editor.add ] )
		] );
	};

	SettingsView.prototype.buildFaq = function () {
		var draft = this.draft;
		var limits = this.data.limits;

		var editor = this.listEditor( 'faq', limits.faq, S.faqAdd, S.faqEmpty, function () {
			return { id: '', question: '', answer: '' };
		}, function ( item, index, actions, changed ) {
			var prefix = 'cc-faq-' + index;
			var question = h( 'input', { id: prefix + '-question', className: 'cc-input', type: 'text', placeholder: S.faqQuestionPlaceholder } );
			question.value = item.question;
			question.addEventListener( 'input', function () {
				item.question = question.value;
				changed();
			} );

			var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
			var answer = h( 'textarea', { id: prefix + '-answer', className: 'cc-input cc-textarea', rows: '3', placeholder: S.faqAnswerPlaceholder } );
			answer.value = item.answer;

			function count() {
				var used = Array.from( answer.value ).length;
				counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limits.faq_answer );
				counter.classList.toggle( 'is-over', used > limits.faq_answer );
			}

			answer.addEventListener( 'input', function () {
				item.answer = answer.value;
				count();
				changed();
			} );
			count();

			return h( 'div', { className: 'cc-invite' }, [
				h( 'div', { className: 'cc-invite__head' }, [ h( 'strong', { text: String( S.faqNumber ).replace( '%s', fmt.number( index + 1 ) ) } ) ].concat( actions ) ),
				h( 'div', { className: 'cc-field' }, [ h( 'label', { className: 'cc-label', for: question.id, text: S.faqQuestion } ), question ] ),
				h( 'div', { className: 'cc-field' }, [
					h( 'div', { className: 'cc-label-row' }, [ h( 'label', { className: 'cc-label', for: answer.id, text: S.faqAnswer } ), counter ] ),
					answer
				] )
			] );
		} );

		return h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-faq-title' }, [
			h( 'div', { className: 'cc-settings__head' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-faq-title', text: S.faqTitle } ),
					h( 'p', { className: 'cc-hint', text: S.faqIntro } )
				] ),
				this.buildSwitch( 'cc-faq-enabled', S.faqOn, draft.faq_enabled, function ( on ) {
					draft.faq_enabled = on;
				} )
			] ),
			editor.list,
			this.fieldError( 'faq' ),
			h( 'div', {}, [ editor.add ] )
		] );
	};

	/**
	 * SMS to visitors who left the site before an agent replied.
	 */
	SettingsView.prototype.buildSms = function () {
		var self = this;
		var draft = this.draft;
		var limit = this.data.limits.sms_message;

		function input( key, label, attrs, hint ) {
			var field = h( 'input', Object.assign( { id: 'cc-' + key.replace( /_/g, '-' ), className: 'cc-input', type: 'text', dir: 'ltr', autocomplete: 'off', spellcheck: 'false' }, attrs || {} ) );
			field.value = draft[ key ];
			field.addEventListener( 'input', function () {
				draft[ key ] = field.value;
				self.clearError( key );
				result.hidden = true;
			} );
			return h( 'div', { className: 'cc-field' }, [
				h( 'label', { className: 'cc-label', for: field.id, text: label } ),
				field,
				hint ? h( 'p', { className: 'cc-hint', text: hint } ) : null,
				self.fieldError( key )
			] );
		}

		var kavenegar = h( 'div', { className: 'cc-bot-fields' }, [
			input( 'sms_api_key', S.smsApiKey, { type: 'password' } )
		] );
		var melipayamak = h( 'div', { className: 'cc-bot-fields' }, [
			input( 'sms_username', S.smsUsername ),
			input( 'sms_password', S.smsPassword, { type: 'password' } )
		] );
		var sender = input( 'sms_sender', S.smsSender, { inputmode: 'numeric' }, ' ' );
		var senderHint = sender.querySelector( '.cc-hint' );

		function showProvider() {
			var isKavenegar = draft.sms_provider === 'kavenegar';
			kavenegar.hidden = ! isKavenegar;
			melipayamak.hidden = isKavenegar;
			senderHint.textContent = isKavenegar ? S.smsSenderOptional : S.smsSenderRequired;
			sender.querySelector( 'input' ).placeholder = isKavenegar ? '10008663' : '50004001234567';
		}

		var providers = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-sms-provider-label' }, [
			[ 'kavenegar', S.smsKavenegar, S.smsKavenegarHint ],
			[ 'melipayamak', S.smsMelipayamak, S.smsMelipayamakHint ]
		].map( function ( option ) {
			var radio = h( 'input', { id: 'cc-sms-provider-' + option[ 0 ], type: 'radio', name: 'cc-sms-provider', value: option[ 0 ], checked: draft.sms_provider === option[ 0 ] } );
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					draft.sms_provider = option[ 0 ];
					showProvider();
					result.hidden = true;
				}
			} );
			return h( 'label', { className: 'cc-radio cc-radio--rich', for: radio.id }, [ radio, h( 'span', {}, [ h( 'strong', { text: option[ 1 ] } ), h( 'small', { text: option[ 2 ] } ) ] ) ] );
		} ) );

		var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
		var message = h( 'textarea', { id: 'cc-sms-message', className: 'cc-input cc-textarea', rows: '3', placeholder: this.data.defaults.sms_message } );
		message.value = draft.sms_message;

		function count() {
			var used = Array.from( message.value ).length;
			counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limit );
			counter.classList.toggle( 'is-over', used > limit );
		}

		message.addEventListener( 'input', function () {
			draft.sms_message = message.value;
			count();
			self.clearError( 'sms_message' );
		} );
		count();

		// Test message to a number the administrator types.
		var result = h( 'p', { className: 'cc-bot-result', role: 'status', hidden: true } );
		var phone = h( 'input', { id: 'cc-sms-test-phone', className: 'cc-input', type: 'tel', dir: 'ltr', inputmode: 'tel', placeholder: '09121234567', autocomplete: 'off' } );
		var test = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary',
			text: S.smsTest,
			onClick: function () {
				test.disabled = true;
				result.hidden = true;
				api.smsTest( {
					provider: draft.sms_provider,
					api_key: String( draft.sms_api_key ).trim(),
					username: String( draft.sms_username ).trim(),
					password: String( draft.sms_password ),
					sender: String( draft.sms_sender ).trim(),
					phone: phone.value.trim()
				} ).then( function () {
					result.textContent = S.smsTestSent;
					result.className = 'cc-bot-result is-ok';
				} ).catch( function ( error ) {
					var fields = ( error.data && error.data.fields ) || {};
					var first = Object.keys( fields )[ 0 ];
					result.textContent = first ? fields[ first ] : error.message;
					result.className = 'cc-bot-result is-error';
				} ).then( function () {
					result.hidden = false;
					test.disabled = false;
				} );
			}
		} );

		showProvider();

		var phoneOff = this.data.settings.form_phone === 'off';

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-sms-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-sms-title', text: S.smsTitle } ),
						h( 'p', { className: 'cc-hint', text: S.smsIntro } )
					] ),
					this.buildSwitch( 'cc-sms-enabled', S.smsOn, draft.sms_enabled, function ( on ) {
						draft.sms_enabled = on;
						self.clearError( 'sms_enabled' );
					} )
				] ),
				phoneOff ? h( 'p', { className: 'cc-banner cc-banner--warning', role: 'note' }, [ icon( 'alert' ), h( 'span', { text: S.smsPhoneOff } ) ] ) : null,
				h( 'span', { className: 'cc-label', id: 'cc-sms-provider-label', text: S.smsProvider } ),
				providers,
				kavenegar,
				melipayamak,
				sender,
				this.fieldError( 'sms_provider' ),
				this.fieldError( 'sms_enabled' )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-sms-text-title' }, [
				h( 'h2', { id: 'cc-sms-text-title', text: S.smsTextTitle } ),
				h( 'div', { className: 'cc-label-row' }, [ h( 'label', { className: 'cc-label', for: message.id, text: S.smsMessage } ), counter ] ),
				message,
				h( 'p', { className: 'cc-hint', text: S.smsMessageHint } ),
				this.fieldError( 'sms_message' ),
				this.numberField( 'sms_delay', S.smsDelay, S.minutes, S.smsDelayHint )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-sms-test-title' }, [
				h( 'h2', { id: 'cc-sms-test-title', text: S.smsTestTitle } ),
				h( 'p', { className: 'cc-hint', text: S.smsTestIntro } ),
				h( 'label', { className: 'cc-label', for: phone.id, text: S.smsTestPhone } ),
				h( 'div', { className: 'cc-bot-test' }, [ phone, test, result ] )
			] )
		] );
	};

	/**
	 * AI: the service, the bot that answers visitors, and reply suggestions.
	 */
	SettingsView.prototype.buildAi = function () {
		var self = this;
		var draft = this.draft;
		var limits = this.data.limits;
		var defaults = this.data.defaults;

		function input( key, label, attrs, hint ) {
			var field = h( 'input', Object.assign( { id: 'cc-' + key.replace( /_/g, '-' ), className: 'cc-input', type: 'text', autocomplete: 'off', spellcheck: 'false' }, attrs || {} ) );
			field.value = draft[ key ];
			field.addEventListener( 'input', function () {
				draft[ key ] = field.value;
				self.clearError( key );
				result.hidden = true;
			} );
			return h( 'div', { className: 'cc-field' }, [
				h( 'label', { className: 'cc-label', for: field.id, text: label } ),
				field,
				hint ? h( 'p', { className: 'cc-hint', text: hint } ) : null,
				self.fieldError( key )
			] );
		}

		var keyField = input( 'ai_api_key', S.aiApiKey, { type: 'password', dir: 'ltr' } );
		var baseField = input( 'ai_base_url', S.aiBaseUrl, { type: 'url', dir: 'ltr' }, ' ' );
		var modelField = input( 'ai_model', S.aiModel, { dir: 'ltr' }, ' ' );
		var result = h( 'p', { className: 'cc-bot-result', role: 'status', hidden: true } );

		// Presets fill in their own address; "another service" needs one.
		function showProvider() {
			var preset = defaults.ai_base_urls[ draft.ai_provider ] || '';
			baseField.querySelector( 'input' ).placeholder = preset || 'https://api.example.com/v1';
			baseField.querySelector( '.cc-hint' ).textContent = preset ? String( S.aiBaseUrlPresetHint ).replace( '%s', preset ) : S.aiBaseUrlCustomHint;
			baseField.querySelector( '.cc-label' ).textContent = preset ? S.aiBaseUrl : S.aiBaseUrlRequired;
		}

		modelField.querySelector( 'input' ).placeholder = 'gpt-4o-mini';
		modelField.querySelector( '.cc-hint' ).textContent = S.aiModelHint;

		var providers = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-ai-provider-label' }, [
			[ 'gapgpt', S.aiGapGpt, S.aiGapGptHint ],
			[ 'avalai', S.aiAvalAi, S.aiAvalAiHint ],
			[ 'openai', S.aiOpenAi, S.aiOpenAiHint ],
			[ 'custom', S.aiCustom, S.aiCustomHint ]
		].map( function ( option ) {
			var radio = h( 'input', { id: 'cc-ai-provider-' + option[ 0 ], type: 'radio', name: 'cc-ai-provider', value: option[ 0 ], checked: draft.ai_provider === option[ 0 ] } );
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					draft.ai_provider = option[ 0 ];
					showProvider();
					result.hidden = true;
				}
			} );
			return h( 'label', { className: 'cc-radio cc-radio--rich', for: radio.id }, [ radio, h( 'span', {}, [ h( 'strong', { text: option[ 1 ] } ), h( 'small', { text: option[ 2 ] } ) ] ) ] );
		} ) );

		var test = h( 'button', {
			type: 'button',
			className: 'cc-button cc-button--secondary',
			text: S.aiTest,
			onClick: function () {
				test.disabled = true;
				result.hidden = true;
				api.aiTest( {
					provider: draft.ai_provider,
					api_key: String( draft.ai_api_key ).trim(),
					base_url: String( draft.ai_base_url ).trim(),
					model: String( draft.ai_model ).trim()
				} ).then( function ( answer ) {
					result.textContent = S.aiTestOk + ' ' + answer.text;
					result.className = 'cc-bot-result is-ok';
				} ).catch( function ( error ) {
					var fields = ( error.data && error.data.fields ) || {};
					var first = Object.keys( fields )[ 0 ];
					result.textContent = first ? fields[ first ] : error.message;
					result.className = 'cc-bot-result is-error';
				} ).then( function () {
					result.hidden = false;
					test.disabled = false;
				} );
			}
		} );

		showProvider();

		// When the bot answers.
		var modes = h( 'div', { className: 'cc-radios', role: 'radiogroup', 'aria-labelledby': 'cc-ai-mode-label' }, [
			[ 'offline', S.aiModeOffline, S.aiModeOfflineHint ],
			[ 'always', S.aiModeAlways, S.aiModeAlwaysHint ]
		].map( function ( option ) {
			var radio = h( 'input', { id: 'cc-ai-mode-' + option[ 0 ], type: 'radio', name: 'cc-ai-mode', value: option[ 0 ], checked: draft.ai_bot_mode === option[ 0 ] } );
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					draft.ai_bot_mode = option[ 0 ];
				}
			} );
			return h( 'label', { className: 'cc-radio cc-radio--rich', for: radio.id }, [ radio, h( 'span', {}, [ h( 'strong', { text: option[ 1 ] } ), h( 'small', { text: option[ 2 ] } ) ] ) ] );
		} ) );

		var nameField = input( 'ai_bot_name', S.aiBotName, { placeholder: defaults.ai_bot_name, maxlength: '40' } );

		var counter = h( 'span', { className: 'cc-counter', 'aria-live': 'polite' } );
		var instructions = h( 'textarea', { id: 'cc-ai-instructions', className: 'cc-input cc-textarea', rows: '4', placeholder: S.aiInstructionsPlaceholder } );
		instructions.value = draft.ai_instructions;

		function count() {
			var used = Array.from( instructions.value ).length;
			counter.textContent = fmt.number( used ) + ' / ' + fmt.number( limits.ai_instructions );
			counter.classList.toggle( 'is-over', used > limits.ai_instructions );
		}

		instructions.addEventListener( 'input', function () {
			draft.ai_instructions = instructions.value;
			count();
			self.clearError( 'ai_instructions' );
		} );
		count();

		return h( 'div', { className: 'cc-settings' }, [
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-ai-title' }, [
				h( 'div', {}, [
					h( 'h2', { id: 'cc-ai-title', text: S.aiTitle } ),
					h( 'p', { className: 'cc-hint', text: S.aiIntro } )
				] ),
				h( 'span', { className: 'cc-label', id: 'cc-ai-provider-label', text: S.aiProvider } ),
				providers,
				h( 'div', { className: 'cc-bot-fields' }, [ keyField, modelField ] ),
				baseField,
				this.fieldError( 'ai_provider' ),
				h( 'div', { className: 'cc-bot-test' }, [ test, result ] )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-ai-bot-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-ai-bot-title', text: S.aiBotTitle } ),
						h( 'p', { className: 'cc-hint', text: S.aiBotIntro } )
					] ),
					this.buildSwitch( 'cc-ai-bot-enabled', S.aiBotOn, draft.ai_bot_enabled, function ( on ) {
						draft.ai_bot_enabled = on;
						self.clearError( 'ai_api_key' );
					} )
				] ),
				h( 'span', { className: 'cc-label', id: 'cc-ai-mode-label', text: S.aiMode } ),
				modes,
				nameField,
				h( 'div', { className: 'cc-label-row' }, [ h( 'label', { className: 'cc-label', for: instructions.id, text: S.aiInstructions } ), counter ] ),
				instructions,
				h( 'p', { className: 'cc-hint', text: S.aiInstructionsHint } ),
				this.fieldError( 'ai_instructions' ),
				this.buildKnowledgePicker(),
				this.numberField( 'ai_max_replies', S.aiMaxReplies, S.aiReplies, S.aiMaxRepliesHint )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-ai-suggest-title' }, [
				h( 'div', { className: 'cc-settings__head' }, [
					h( 'div', {}, [
						h( 'h2', { id: 'cc-ai-suggest-title', text: S.aiSuggestTitle } ),
						h( 'p', { className: 'cc-hint', text: S.aiSuggestIntro } )
					] ),
					this.buildSwitch( 'cc-ai-suggest-enabled', S.aiSuggestOn, draft.ai_suggest_enabled, function ( on ) {
						draft.ai_suggest_enabled = on;
						self.clearError( 'ai_api_key' );
					} )
				] )
			] ),
			h( 'section', { className: 'cc-card cc-settings__section', 'aria-labelledby': 'cc-ai-limit-title' }, [
				h( 'h2', { id: 'cc-ai-limit-title', text: S.aiLimitTitle } ),
				this.numberField( 'ai_daily_limit', S.aiDailyLimit, S.aiRequests, S.aiDailyLimitHint )
			] )
		] );
	};

	/**
	 * The pages, posts and products the bot knows, picked by search.
	 */
	SettingsView.prototype.buildKnowledgePicker = function () {
		var self = this;
		var max = this.data.limits.ai_knowledge;
		var chosen = h( 'ul', { className: 'cc-knowledge' } );
		var results = h( 'ul', { className: 'cc-user-results cc-knowledge-results', 'aria-live': 'polite' } );
		var ticket = 0;
		var timer = null;
		var search = h( 'input', { id: 'cc-ai-knowledge-search', className: 'cc-input', type: 'search', placeholder: S.aiKnowledgeSearch, autocomplete: 'off' } );

		function draw() {
			chosen.replaceChildren.apply( chosen, self.knowledge.length ? self.knowledge.map( function ( item ) {
				return h( 'li', { className: 'cc-knowledge__item' }, [
					h( 'span', {}, [ h( 'strong', { text: item.title } ), h( 'small', { text: item.type_label } ) ] ),
					h( 'button', {
						type: 'button',
						className: 'cc-icon-button',
						title: S.remove,
						'aria-label': S.remove + ': ' + item.title,
						onClick: function () {
							self.knowledge = self.knowledge.filter( function ( other ) {
								return other.id !== item.id;
							} );
							draw();
							self.clearError( 'ai_knowledge_ids' );
							self.syncNav();
						}
					}, [ icon( 'close' ) ] )
				] );
			} ) : [ h( 'li', { className: 'cc-hint', text: S.aiKnowledgeEmpty } ) ] );
		}

		function find( term ) {
			var mine = ++ticket;
			api.content( term ).then( function ( items ) {
				if ( mine !== ticket ) {
					return;
				}
				results.replaceChildren.apply( results, items.length ? items.map( function ( item ) {
					var picked = self.knowledge.some( function ( other ) {
						return other.id === item.id;
					} );
					return h( 'li', {}, [
						h( 'button', {
							type: 'button',
							className: 'cc-user cc-content' + ( picked ? ' is-selected' : '' ),
							disabled: picked || self.knowledge.length >= max,
							onClick: function () {
								self.knowledge.push( item );
								draw();
								find( search.value.trim() );
								self.syncNav();
							}
						}, [ h( 'span', {}, [ h( 'strong', { text: item.title } ), h( 'small', { text: item.type_label } ) ] ), icon( picked ? 'check' : 'plus' ) ] )
					] );
				} ) : [ h( 'li', { className: 'cc-hint', text: S.noContent } ) ] );
			} ).catch( function ( error ) {
				results.replaceChildren( h( 'li', { className: 'cc-field-error', text: error.message } ) );
			} );
		}

		search.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				find( search.value.trim() );
			}, 250 );
		} );

		draw();
		find( '' );

		return h( 'div', { className: 'cc-field' }, [
			h( 'label', { className: 'cc-label', for: search.id, text: fmt.format( S.aiKnowledge, max ) } ),
			h( 'p', { className: 'cc-hint', text: S.aiKnowledgeHint } ),
			chosen,
			search,
			results,
			this.fieldError( 'ai_knowledge_ids' )
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
