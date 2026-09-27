<?php
/**
 * Visitor-facing endpoints used by the chat widget.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Ai\Assistant;
use ChatChi\Attachments\AttachmentService;
use ChatChi\Availability\Availability;
use ChatChi\Commerce\CustomerContext;
use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Conversations\RatingService;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Messages\MessageBody;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Conversation;
use ChatChi\Models\Visitor;
use ChatChi\Security\RateLimiter;
use ChatChi\Security\SpamGuard;
use ChatChi\Visitors\VisitorService;

defined( 'ABSPATH' ) || exit;

/**
 * /chatchi/v1/widget/…
 *
 * Visitors are identified by their HttpOnly cookie. Logged-in users who send
 * a valid X-WP-Nonce are identified by their account instead. Every write is
 * checked by RequestGuard against cross-site requests and counted by the
 * rate limiter; starting a conversation is also checked for bots.
 */
final class WidgetController extends ConversationController {

	private VisitorService $visitors;

	private AgentRepository $agents;

	private MessageBody $body;

	private RequestGuard $guard;

	private RateLimiter $limiter;

	private SpamGuard $spam;

	private Availability $availability;

	private ?CustomerContext $context;

	private ?RatingService $ratings;

	private ?Assistant $assistant;

	/**
	 * Rate-limit decisions per request object.
	 *
	 * WordPress runs permission callbacks again after a request, to build
	 * the Allow header (rest_send_allow_header()); each request must still
	 * be counted only once.
	 *
	 * @var \SplObjectStorage<\WP_REST_Request, \WP_Error|null>
	 */
	private \SplObjectStorage $decisions;

	/**
	 * @param ConversationService  $conversations Conversation use cases.
	 * @param MessageService       $messages      Message use cases.
	 * @param Presenter            $presenter     Response shapes.
	 * @param VisitorService       $visitors      Visitor use cases.
	 * @param AgentRepository      $agents        Agent storage, to name assigned agents.
	 * @param MessageBody          $body          Message rules, to expose the length limit.
	 * @param RequestGuard         $guard         Cross-site protection.
	 * @param RateLimiter          $limiter       Rate limits.
	 * @param SpamGuard            $spam          Bot checks for the start form.
	 * @param Availability         $availability  Business hours and agent presence.
	 * @param AttachmentService    $attachments   Files in conversations.
	 * @param CustomerContext|null $context       Page and cart tracking.
	 * @param RatingService|null   $ratings       Ratings of ended chats.
	 * @param Assistant|null       $assistant     The AI bot.
	 */
	public function __construct(
		ConversationService $conversations,
		MessageService $messages,
		Presenter $presenter,
		VisitorService $visitors,
		AgentRepository $agents,
		MessageBody $body,
		RequestGuard $guard,
		RateLimiter $limiter,
		SpamGuard $spam,
		Availability $availability,
		AttachmentService $attachments,
		?CustomerContext $context = null,
		?RatingService $ratings = null,
		?Assistant $assistant = null
	) {
		parent::__construct( $conversations, $messages, $presenter, $attachments );

		$this->visitors     = $visitors;
		$this->agents       = $agents;
		$this->body         = $body;
		$this->guard        = $guard;
		$this->limiter      = $limiter;
		$this->spam         = $spam;
		$this->availability = $availability;
		$this->context      = $context;
		$this->ratings      = $ratings;
		$this->assistant    = $assistant;
		$this->decisions    = new \SplObjectStorage();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base(): string {
		return 'widget';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function register_extra_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/widget/session',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'session' ),
				'permission_callback' => array( $this, 'permit_public' ),
				'args'                => array(
					// The page the widget loaded on; recorded for visitors who chatted.
					'url'   => array(
						'type'      => 'string',
						'maxLength' => 2048,
						'default'   => '',
					),
					'title' => array(
						'type'      => 'string',
						'maxLength' => 500,
						'default'   => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/widget/conversations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permit_start' ),
				'args'                => array(
					'form_token' => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					// Honeypot; see SpamGuard::TRAP_FIELD.
					'website'    => array(
						'type'      => 'string',
						'maxLength' => 500,
						'default'   => '',
					),
					'name'       => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'email'      => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'phone'      => array(
						'type'      => 'string',
						'maxLength' => 50,
					),
					'page_url'   => array(
						'type'      => 'string',
						'maxLength' => 2048,
						'default'   => '',
					),
					'page_title' => array(
						'type'      => 'string',
						'maxLength' => 500,
						'default'   => '',
					),
					// Sent from the leave-a-message form: email is required then.
					'offline'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// The AI bot: answer the visitor's latest message, or hand over to a person.
		foreach ( array( 'bot' => 'bot_answer', 'handoff' => 'bot_handoff' ) as $path => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/widget/conversations/(?P<id>\d+)/' . $path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permit_actor' ),
					'args'                => $this->id_arg(),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/widget/conversations/(?P<id>\d+)/rating',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rate' ),
				'permission_callback' => array( $this, 'permit_actor' ),
				'args'                => array_merge(
					$this->id_arg(),
					array(
						'score'   => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'maximum'  => 5,
							'required' => true,
						),
						'comment' => array(
							'type'      => 'string',
							'maxLength' => 2000,
							'default'   => '',
						),
					)
				),
			)
		);
	}

	/**
	 * POST /widget/conversations/{id}/rating: the visitor rates their ended chat.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rate( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		if ( ! $this->ratings ) {
			return new \WP_Error( 'chatchi_rating_disabled', __( 'Rating is turned off.', 'chatchi' ), array( 'status' => 403 ) );
		}

		$rated = $this->ratings->rate( $actor, (int) $request['id'], (int) $request['score'], (string) $request['comment'] );

		if ( $rated instanceof \WP_Error ) {
			return $rated;
		}

		$data = $this->present_conversation( $rated, $actor );

		return $data instanceof \WP_Error ? $data : $this->respond( $data );
	}

	/**
	 * POST /widget/conversations/{id}/bot: the AI bot answers the visitor's
	 * latest message, when it looks after this conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bot_answer( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		if ( ! $this->assistant ) {
			return $this->respond( array( 'answered' => false, 'handoff' => false ) );
		}

		$result = $this->assistant->answer( $actor, (int) $request['id'] );

		return $result instanceof \WP_Error ? $result : $this->respond( $result );
	}

	/**
	 * POST /widget/conversations/{id}/handoff: the visitor asks for a person.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bot_handoff( \WP_REST_Request $request ) {
		$actor = $this->actor( $request );

		if ( $actor instanceof \WP_Error ) {
			return $actor;
		}

		if ( ! $this->assistant ) {
			return new \WP_Error( 'chatchi_ai_disabled', __( 'This is not available right now.', 'chatchi' ), array( 'status' => 404 ) );
		}

		$conversation = $this->assistant->request_person( $actor, (int) $request['id'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$data = $this->present_conversation( $conversation, $actor );

		return $data instanceof \WP_Error ? $data : $this->respond( $data );
	}

	/**
	 * Permission callback for endpoints that need no identity yet.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permit_public( \WP_REST_Request $request ) {
		return $this->guard->is_same_site( $request ) ? true : $this->guard->rejection();
	}

	/**
	 * Permission callback for starting a conversation: same-site, and within
	 * the rate limits. Runs before the arguments are validated, so malformed
	 * attempts are counted too.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permit_start( \WP_REST_Request $request ) {
		$allowed = $this->permit_public( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		return $this->once(
			$request,
			function () use ( $request ) {
				// A merely quick person is asked to wait without using up their allowance.
				$too_fast = $this->spam->too_fast( (string) $request->get_param( 'form_token' ) );

				if ( $too_fast instanceof \WP_Error ) {
					return $too_fast;
				}

				$visitor = $this->visitors->current();

				return $this->limiter->check( 'start', $visitor instanceof Visitor ? $visitor->id : null );
			}
		) ?? true;
	}

	/**
	 * Runs a rate-limit check once per request and remembers the result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param callable         $check   Returns a WP_Error or null.
	 * @return \WP_Error|null
	 */
	private function once( \WP_REST_Request $request, callable $check ): ?\WP_Error {
		if ( ! $this->decisions->contains( $request ) ) {
			$this->decisions[ $request ] = $check();
		}

		return $this->decisions[ $request ];
	}

	/**
	 * GET /widget/session: everything the widget needs to render on load.
	 *
	 * Never creates an identity or sets a cookie.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function session( \WP_REST_Request $request ) {
		$visitor = $this->visitors->current();

		if ( $visitor instanceof \WP_Error ) {
			return $visitor;
		}

		$conversation = null;
		$unread       = 0;

		if ( $visitor && $this->context && '' !== (string) $request['url'] ) {
			$this->context->record( $visitor, (string) $request['url'], (string) $request['title'] );
		}

		if ( $visitor ) {
			$open = $this->conversations->open_for( $visitor );

			if ( $open instanceof \WP_Error ) {
				return $open;
			}

			if ( $open ) {
				$conversation = $this->present_conversation( $open, Actor::visitor( $visitor ) );
				$unread       = $this->messages->unread_count( Actor::visitor( $visitor ), $open->id );

				if ( $conversation instanceof \WP_Error ) {
					return $conversation;
				}

				if ( $unread instanceof \WP_Error ) {
					return $unread;
				}
			}
		}

		return $this->respond(
			array(
				'visitor'            => $visitor ? $this->presenter->visitor_self( $visitor ) : null,
				'conversation'       => $conversation,
				'unread_count'       => $unread,
				'form'               => $this->visitors->form_config(),
				'max_message_length' => $this->body->max_length(),
				'form_token'         => $this->spam->token(),
				'availability'       => $this->availability->state(),
				'attachments'        => $this->attachments->client_config(),
			)
		);
	}

	/**
	 * POST /widget/conversations: identifies the visitor from the start form
	 * and returns their open conversation, creating both when needed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( \WP_REST_Request $request ) {
		$spam = $this->spam->check( (string) $request['form_token'], (string) $request[ SpamGuard::TRAP_FIELD ] );

		if ( $spam instanceof \WP_Error ) {
			return $spam;
		}

		$visitor = $this->visitors->start(
			array(
				'name'  => $request['name'],
				'email' => $request['email'],
				'phone' => $request['phone'],
			),
			(bool) $request['offline']
		);

		if ( $visitor instanceof \WP_Error ) {
			return $visitor;
		}

		$conversation = $this->conversations->start( $visitor, (string) $request['page_url'] );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( $this->context ) {
			$this->context->record( $visitor, (string) $request['page_url'], (string) $request['page_title'] );
		}

		$data = $this->present_conversation( $conversation, Actor::visitor( $visitor ) );

		if ( $data instanceof \WP_Error ) {
			return $data;
		}

		return $this->respond(
			array(
				'visitor'      => $this->presenter->visitor_self( $visitor ),
				'conversation' => $data,
			),
			201
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function actor( \WP_REST_Request $request ) {
		if ( ! $this->guard->is_same_site( $request ) ) {
			return $this->guard->rejection();
		}

		$visitor = $this->visitors->current();

		if ( $visitor instanceof \WP_Error ) {
			return $visitor;
		}

		if ( ! $visitor instanceof Visitor ) {
			return new \WP_Error(
				'chatchi_no_visitor',
				__( 'Start a conversation first.', 'chatchi' ),
				array( 'status' => 401 )
			);
		}

		return Actor::visitor( $visitor );
	}

	/**
	 * Counts visitor writes: sending messages, typing signals, marking read
	 * and closing.
	 * Reads (polling) are not limited.
	 *
	 * {@inheritDoc}
	 */
	protected function throttle( \WP_REST_Request $request, Actor $actor ): ?\WP_Error {
		if ( 'GET' === $request->get_method() || 'HEAD' === $request->get_method() ) {
			return null;
		}

		$route  = (string) $request->get_route();
		$action = 'write';

		if ( '/messages' === substr( $route, -9 ) ) {
			$action = 'message';
		} elseif ( '/typing' === substr( $route, -7 ) ) {
			$action = 'typing';
		} elseif ( '/attachments' === substr( $route, -12 ) ) {
			$action = 'upload';
		} elseif ( '/bot' === substr( $route, -4 ) ) {
			$action = 'bot';
		}

		return $this->once(
			$request,
			function () use ( $action, $actor ) {
				return $this->limiter->check( $action, $actor->id() );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function present_conversation( Conversation $conversation, Actor $actor ) {
		try {
			$agent = $conversation->agent_id > 0 ? $this->agents->find( $conversation->agent_id ) : null;
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		$data = $this->presenter->conversation_for_visitor( $conversation, $agent );

		// Whether the AI bot looks after this conversation.
		$data['bot'] = $this->assistant ? $this->assistant->is_active( $conversation ) : false;

		return $data;
	}
}
