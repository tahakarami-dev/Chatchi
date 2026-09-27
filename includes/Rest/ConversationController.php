<?php
/**
 * Endpoints shared by the widget and the agent panel.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Conversation;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Message, read and close endpoints under /{base}/conversations/{id}.
 *
 * Subclasses decide how the actor is authenticated and add their own
 * routes. Access to the conversation itself is always enforced by the
 * services, never by the controller alone.
 */
abstract class ConversationController implements RouteProvider {

	protected ConversationService $conversations;

	protected MessageService $messages;

	protected Presenter $presenter;

	/**
	 * @param ConversationService $conversations Conversation use cases.
	 * @param MessageService      $messages      Message use cases.
	 * @param Presenter           $presenter     Response shapes.
	 */
	public function __construct( ConversationService $conversations, MessageService $messages, Presenter $presenter ) {
		$this->conversations = $conversations;
		$this->messages      = $messages;
		$this->presenter     = $presenter;
	}

	/**
	 * Route prefix, e.g. "widget" or "agent".
	 */
	abstract protected function base(): string;

	/**
	 * Resolves the authenticated actor of the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Actor|\WP_Error
	 */
	abstract protected function actor( \WP_REST_Request $request );

	/**
	 * Presents a conversation to the actor.
	 *
	 * @param Conversation $conversation Conversation.
	 * @param Actor        $actor        Viewer.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function present_conversation( Conversation $conversation, Actor $actor );

	/**
	 * Registers routes specific to the subclass.
	 */
	abstract protected function register_extra_routes(): void;

	/**
	 * Registers all routes of this controller.
	 */
	public function register_routes(): void {
		$route = '/' . $this->base() . '/conversations/(?P<id>\d+)';

		register_rest_route(
			self::NAMESPACE,
			$route . '/messages',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_messages' ),
					'permission_callback' => array( $this, 'permit_actor' ),
					'args'                => array_merge( $this->id_arg(), $this->paging_args() ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_message' ),
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
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$route . '/read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'up_to_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$route . '/close',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'close' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$route . '/typing',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'typing' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'typing' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					)
				),
			)
		);

		$this->register_extra_routes();
	}

	/**
	 * Permission callback: the request must have an authenticated actor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permit_actor( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		return $this->throttle( $request, $actor ) ?? true;
	}

	/**
	 * Applies rate limits to a request. Runs once per request, from the
	 * permission check, so every attempt is counted exactly once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param Actor            $actor   Authenticated actor.
	 * @return \WP_Error|null Error when the request is refused.
	 */
	protected function throttle( \WP_REST_Request $request, Actor $actor ): ?\WP_Error {
		return null;
	}

	/**
	 * GET …/messages
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_messages( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$page = $this->messages->messages(
			$actor,
			(int) $request['id'],
			array(
				'after_id'  => $request['after_id'],
				'before_id' => $request['before_id'],
				'limit'     => $request['limit'],
			)
		);

		if ( $page instanceof \WP_Error ) {
			return $page;
		}

		return $this->respond( $this->presenter->message_page( $page, $actor ) );
	}

	/**
	 * POST …/messages
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_message( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$client_id = $request['client_id'];
		$message   = $this->messages->send(
			$actor,
			(int) $request['id'],
			$request['body'],
			is_string( $client_id ) && '' !== $client_id ? $client_id : null
		);

		if ( $message instanceof \WP_Error ) {
			return $message;
		}

		$agent  = $actor->agent_model();
		$agents = $agent ? array( $agent->id => $agent ) : array();

		return $this->respond( $this->presenter->message( $message, $actor, $agents ), 201 );
	}

	/**
	 * POST …/read
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_read( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$marked = $this->messages->mark_read( $actor, (int) $request['id'], (int) $request['up_to_id'] );

		if ( $marked instanceof \WP_Error ) {
			return $marked;
		}

		return $this->respond( array( 'marked' => $marked ) );
	}

	/**
	 * POST …/typing: the actor is typing (or stopped).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function typing( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$recorded = $this->messages->typing( $actor, (int) $request['id'], (bool) $request['typing'] );

		if ( $recorded instanceof \WP_Error ) {
			return $recorded;
		}

		return $this->respond( array( 'recorded' => $recorded ) );
	}

	/**
	 * POST …/close
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function close( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		$conversation = $this->conversations->close( $actor, (int) $request['id'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$data = $this->present_conversation( $conversation, $actor );

		return $data instanceof \WP_Error ? $data : $this->respond( $data );
	}

	/**
	 * Builds a response that browsers, proxies and CDNs must not cache.
	 *
	 * @param mixed $data   Response body.
	 * @param int   $status HTTP status.
	 */
	protected function respond( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function id_arg(): array {
		return array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function paging_args(): array {
		return array(
			'after_id'  => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'before_id' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'limit'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 50,
				'default' => 30,
			),
		);
	}

	/**
	 * Logs an unexpected failure and returns a safe, generic error.
	 *
	 * @param \Throwable $error Failure.
	 */
	protected function failure( \Throwable $error ): \WP_Error {
		return Failure::report( $error, 'REST', 'chatchi_unavailable' );
	}
}
