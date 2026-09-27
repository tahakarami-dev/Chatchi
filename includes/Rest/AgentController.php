<?php
/**
 * Agent-facing endpoints used by the ChatChi panel.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Admin\PanelTheme;
use ChatChi\Ai\Assistant;
use ChatChi\Attachments\AttachmentService;
use ChatChi\Commerce\CustomerContext;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Agent;
use ChatChi\Models\Conversation;
use ChatChi\Notifications\Transcript;

defined( 'ABSPATH' ) || exit;

/**
 * /chatchi/v1/agent/…
 *
 * Requires a logged-in WordPress user whose request carries a valid
 * X-WP-Nonce (WordPress rejects cookie-authenticated requests without one,
 * which is the CSRF protection here) and who has an active agent record.
 */
final class AgentController extends ConversationController {

	private AgentRepository $agents;

	private ?Transcript $transcript;

	private ?CustomerContext $context;

	private ?VisitorRepository $visitors;

	private ?Assistant $assistant;

	/**
	 * Agent whose presence was already recorded in this request.
	 */
	private int $touched = 0;

	/**
	 * @param ConversationService    $conversations Conversation use cases.
	 * @param MessageService         $messages      Message use cases.
	 * @param Presenter              $presenter     Response shapes.
	 * @param AgentRepository        $agents        Agent storage.
	 * @param AttachmentService      $attachments   Files in conversations.
	 * @param Transcript|null        $transcript    Transcript emails.
	 * @param CustomerContext|null   $context       Page, cart and orders.
	 * @param VisitorRepository|null $visitors      Visitor storage.
	 * @param Assistant|null         $assistant     AI reply suggestions.
	 */
	public function __construct( ConversationService $conversations, MessageService $messages, Presenter $presenter, AgentRepository $agents, AttachmentService $attachments, ?Transcript $transcript = null, ?CustomerContext $context = null, ?VisitorRepository $visitors = null, ?Assistant $assistant = null ) {
		parent::__construct( $conversations, $messages, $presenter, $attachments );

		$this->agents     = $agents;
		$this->transcript = $transcript;
		$this->context    = $context;
		$this->visitors   = $visitors;
		$this->assistant  = $assistant;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base(): string {
		return 'agent';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function register_extra_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/me',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'me' ),
					'permission_callback' => array( $this, 'permit_actor' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_me' ),
					'permission_callback' => array( $this, 'permit_actor' ),
					'args'                => array(
						'status' => array(
							'type' => 'string',
							'enum' => array( Agent::STATUS_ONLINE, Agent::STATUS_AWAY ),
						),
						// The panel's color theme, kept per user.
						'theme'  => array(
							'type' => 'string',
							'enum' => PanelTheme::THEMES,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'inbox' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array( '', Conversation::STATUS_NEW, Conversation::STATUS_ACTIVE, Conversation::STATUS_CLOSED ),
						'default' => '',
					),
					'search'   => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'assigned' => array(
						'type'    => 'string',
						'enum'    => array( '', 'mine', 'unassigned' ),
						'default' => '',
					),
					'tag'      => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversation' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/notes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'body'      => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 20000,
						),
						'client_id' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/assign',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'agent_id' => array(
							'type'     => 'integer',
							'minimum'  => 0,
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/tags',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_tags' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'tags' => array(
							'type'     => 'array',
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'maxItems' => 50,
							'required' => true,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/transcript',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_transcript' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/suggest',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/conversations/(?P<id>\d+)/customer',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'customer' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/tags',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tags' ),
				'permission_callback' => array( $this, 'permit_actor' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/team',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'team' ),
				'permission_callback' => array( $this, 'permit_actor' ),
			)
		);
	}

	/**
	 * GET /agent/me
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function me( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		return $this->respond(
			$this->presenter->agent_self( $actor->agent_model() ) + array(
				'attachments' => $this->attachments->client_config( $actor ),
				'theme'       => PanelTheme::get( $actor->agent_model()->user_id ),
			)
		);
	}

	/**
	 * POST …/notes: an internal note, never shown to the visitor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_note( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$client_id = $request['client_id'];
		$note      = $this->messages->add_note( $actor, (int) $request['id'], $request['body'], is_string( $client_id ) && '' !== $client_id ? $client_id : null );

		if ( $note instanceof \WP_Error ) {
			return $note;
		}

		$agent = $actor->agent_model();

		return $this->respond( $this->presenter->message( $note, $actor, array( $agent->id => $agent ) ), 201 );
	}

	/**
	 * POST …/assign: hands the conversation to an agent (0 unassigns).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function assign( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$conversation = $this->conversations->assign( $actor, (int) $request['id'], (int) $request['agent_id'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$data = $this->present_conversation( $conversation, $actor );

		return $data instanceof \WP_Error ? $data : $this->respond( $data );
	}

	/**
	 * PUT/PATCH/POST …/tags: replaces the conversation's tags.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_tags( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$tags = $this->conversations->set_tags( $actor, (int) $request['id'], $request['tags'] );

		return $tags instanceof \WP_Error ? $tags : $this->respond( array( 'tags' => $tags ) );
	}

	/**
	 * POST …/suggest: an AI draft of the next reply, for the agent to edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		if ( ! $this->assistant ) {
			return new \WP_Error( 'chatchi_unavailable', __( 'This is not available right now.', 'chatchi' ), array( 'status' => 503 ) );
		}

		$text = $this->assistant->suggest( $actor, (int) $request['id'] );

		return $text instanceof \WP_Error ? $text : $this->respond( array( 'text' => $text ) );
	}

	/**
	 * POST …/transcript: emails the visitor a copy of the conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_transcript( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		if ( ! $this->transcript ) {
			return new \WP_Error( 'chatchi_unavailable', __( 'This is not available right now.', 'chatchi' ), array( 'status' => 503 ) );
		}

		$to = $this->transcript->send_for( $actor, (int) $request['id'] );

		return $to instanceof \WP_Error ? $to : $this->respond( array( 'sent_to' => $to ) );
	}

	/**
	 * GET …/customer: the visitor's current page and, on WooCommerce shops,
	 * their cart and orders. Orders are only shown to people who may manage
	 * them (filter chatchi_agent_can_see_orders).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function customer( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$conversation = $this->conversations->get( $actor, (int) $request['id'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $this->context || ! $this->visitors ) {
			return new \WP_Error( 'chatchi_unavailable', __( 'This is not available right now.', 'chatchi' ), array( 'status' => 503 ) );
		}

		try {
			$visitor = $this->visitors->find( $conversation->visitor_id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		/**
		 * Filters whether the current agent may see the visitor's orders.
		 *
		 * @param bool $allowed Default: administrators and shop managers.
		 */
		$with_orders = (bool) apply_filters( 'chatchi_agent_can_see_orders', current_user_can( 'manage_options' ) || current_user_can( 'edit_shop_orders' ) );

		return $this->respond( $this->context->for_agent( $visitor, $with_orders ) );
	}

	/**
	 * GET /agent/tags: the most used tags.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tags( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$tags = $this->conversations->popular_tags( $actor );

		return $tags instanceof \WP_Error ? $tags : $this->respond( $tags );
	}

	/**
	 * GET /agent/team: active agents, to transfer conversations to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function team( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		try {
			$agents = $this->agents->all( true );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		return $this->respond(
			array_map(
				function ( Agent $agent ) use ( $actor ): array {
					return $this->presenter->agent( $agent ) + array(
						'online' => $agent->is_available(),
						'is_me'  => $agent->id === $actor->id(),
					);
				},
				$agents
			)
		);
	}

	/**
	 * POST/PATCH /agent/me: the agent sets themselves online or away, and/or
	 * chooses the panel's theme.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_me( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$agent = $actor->agent_model();

		if ( null === $request['status'] && null === $request['theme'] ) {
			return new \WP_Error( 'rest_missing_callback_param', __( 'Nothing to change.', 'chatchi' ), array( 'status' => 400 ) );
		}

		if ( null !== $request['theme'] ) {
			PanelTheme::set( $agent->user_id, (string) $request['theme'] );
		}

		if ( null !== $request['status'] ) {
			try {
				$this->agents->set_status( $agent->id, (string) $request['status'] );
				$agent = $this->agents->find( $agent->id ) ?? $agent;
			} catch ( \Throwable $e ) {
				return $this->failure( $e );
			}

			/**
			 * Fires after an agent changes their availability.
			 *
			 * @param Agent $agent Agent, with the new status.
			 */
			do_action( 'chatchi_agent_status_changed', $agent );
		}

		return $this->respond( $this->presenter->agent_self( $agent ) + array( 'theme' => PanelTheme::get( $agent->user_id ) ) );
	}

	/**
	 * Records the agent's presence: any request from the panel means it is
	 * open. Runs once per request, from the permission check.
	 *
	 * {@inheritDoc}
	 */
	protected function throttle( \WP_REST_Request $request, Actor $actor ): ?\WP_Error {
		$agent = $actor->agent_model();

		if ( $agent && $this->touched !== $agent->id ) {
			$this->touched = $agent->id;

			try {
				$this->agents->touch( $agent->id );
			} catch ( \Throwable $e ) {
				// Presence is best effort; never refuse the request for it.
				$this->failure( $e );
			}
		}

		return null;
	}

	/**
	 * GET /agent/conversations
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function inbox( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$page = $this->conversations->inbox(
			$actor,
			array(
				'status'   => (string) $request['status'],
				'search'   => (string) $request['search'],
				'assigned' => (string) $request['assigned'],
				'tag'      => (string) $request['tag'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);

		if ( $page instanceof \WP_Error ) {
			return $page;
		}

		$items = array();
		foreach ( $page['items'] as $summary ) {
			$items[] = $this->presenter->conversation_for_agent( $summary, $actor );
		}

		$total_pages = (int) ceil( $page['total'] / $page['per_page'] );
		$response    = $this->respond(
			array(
				'items'       => $items,
				'total'       => $page['total'],
				'page'        => $page['page'],
				'per_page'    => $page['per_page'],
				'total_pages' => $total_pages,
				'counts'      => $page['counts'],
			)
		);

		$response->header( 'X-WP-Total', (string) $page['total'] );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * GET /agent/conversations/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_conversation( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$conversation = $this->conversations->get( $actor, (int) $request['id'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$data = $this->present_conversation( $conversation, $actor );

		return $data instanceof \WP_Error ? $data : $this->respond( $data );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function actor( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'chatchi_not_logged_in',
				__( 'Please log in to continue.', 'chatchi' ),
				array( 'status' => 401 )
			);
		}

		try {
			$agent = $this->agents->find_by_user_id( $user_id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( ! $agent ) {
			return new \WP_Error(
				'chatchi_not_agent',
				__( 'Your account is not a chat agent.', 'chatchi' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $agent->is_active ) {
			return new \WP_Error(
				'chatchi_agent_inactive',
				__( 'Your agent account is inactive.', 'chatchi' ),
				array( 'status' => 403 )
			);
		}

		return Actor::agent( $agent );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function present_conversation( Conversation $conversation, Actor $actor ) {
		try {
			$summaries = $this->conversations->summarize( array( $conversation ) );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		return $this->presenter->conversation_for_agent( $summaries[0], $actor );
	}
}
