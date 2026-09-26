<?php
/**
 * Conversation use cases.
 *
 * @package ChatChi
 */

namespace ChatChi\Conversations;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Agent;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Models\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Starting, reading, listing and closing conversations.
 *
 * Every read or write goes through ConversationPolicy. Methods return
 * WP_Error instead of throwing, so REST controllers can pass errors through.
 * A visitor asking for someone else's conversation gets "not found", so
 * conversation IDs cannot be probed.
 */
final class ConversationService {

	private const SEARCH_MAX_LENGTH = 100;

	private ConversationRepository $conversations;

	private VisitorRepository $visitors;

	private AgentRepository $agents;

	private MessageRepository $messages;

	private ConversationPolicy $policy;

	/**
	 * @param ConversationRepository $conversations Conversation storage.
	 * @param VisitorRepository      $visitors      Visitor storage.
	 * @param AgentRepository        $agents        Agent storage.
	 * @param MessageRepository      $messages      Message storage.
	 * @param ConversationPolicy     $policy        Access rules.
	 */
	public function __construct(
		ConversationRepository $conversations,
		VisitorRepository $visitors,
		AgentRepository $agents,
		MessageRepository $messages,
		ConversationPolicy $policy
	) {
		$this->conversations = $conversations;
		$this->visitors      = $visitors;
		$this->agents        = $agents;
		$this->messages      = $messages;
		$this->policy        = $policy;
	}

	/**
	 * Returns the visitor's open conversation, or opens a new one.
	 *
	 * Safe under concurrent requests: the database allows one open
	 * conversation per visitor, and the losing request returns the winner's.
	 *
	 * @param Visitor $visitor  Visitor starting the chat.
	 * @param string  $page_url Page the chat is started from; kept only if it is on this site.
	 * @return Conversation|\WP_Error
	 */
	public function start( Visitor $visitor, string $page_url = '' ) {
		try {
			$open = $this->conversations->find_open_by_visitor( $visitor->id );

			if ( $open ) {
				return $open;
			}

			try {
				$conversation = $this->conversations->create( $visitor->id, $this->sanitize_page_url( $page_url ) );
			} catch ( DatabaseException $e ) {
				$open = $this->conversations->find_open_by_visitor( $visitor->id );

				if ( $open ) {
					return $open;
				}

				throw $e;
			}
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		/**
		 * Fires after a visitor opens a new conversation.
		 *
		 * @param Conversation $conversation New conversation.
		 * @param Visitor      $visitor      Its visitor.
		 */
		do_action( 'chatchi_conversation_started', $conversation, $visitor );

		return $conversation;
	}

	/**
	 * Returns the visitor's open conversation, if any.
	 *
	 * @param Visitor $visitor Visitor.
	 * @return Conversation|null|\WP_Error
	 */
	public function open_for( Visitor $visitor ) {
		try {
			return $this->conversations->find_open_by_visitor( $visitor->id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}
	}

	/**
	 * Returns a conversation the actor is allowed to see.
	 *
	 * @param Actor $actor Requesting actor.
	 * @param int   $id    Conversation ID.
	 * @return Conversation|\WP_Error
	 */
	public function get( Actor $actor, int $id ) {
		try {
			$conversation = $this->conversations->find( $id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( ! $conversation ) {
			return $this->not_found();
		}

		if ( ! $this->policy->can_view( $actor, $conversation ) ) {
			return $actor->is_visitor() ? $this->not_found() : $this->forbidden();
		}

		return $conversation;
	}

	/**
	 * Closes a conversation. Closing an already closed one is a no-op.
	 *
	 * @param Actor $actor Requesting actor.
	 * @param int   $id    Conversation ID.
	 * @return Conversation|\WP_Error The closed conversation.
	 */
	public function close( Actor $actor, int $id ) {
		$conversation = $this->get( $actor, $id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $this->policy->can_close( $actor, $conversation ) ) {
			return $this->forbidden();
		}

		if ( $conversation->is_closed() ) {
			return $conversation;
		}

		try {
			$this->conversations->update_status( $conversation->id, Conversation::STATUS_CLOSED );
			$closed = $this->conversations->find( $conversation->id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( ! $closed ) {
			return $this->not_found();
		}

		$this->status_changed( $closed, $conversation->status );

		/**
		 * Fires after a conversation is closed.
		 *
		 * @param Conversation $conversation Closed conversation.
		 * @param Actor        $actor        Who closed it.
		 */
		do_action( 'chatchi_conversation_closed', $closed, $actor );

		return $closed;
	}

	/**
	 * Records that an agent replied: a new conversation becomes active and is
	 * assigned to the first agent who answers it.
	 *
	 * Called by the message system when an agent sends a message.
	 *
	 * @param Conversation $conversation Conversation replied to.
	 * @param Agent        $agent        Replying agent.
	 * @return Conversation|\WP_Error The updated conversation.
	 */
	public function mark_answered( Conversation $conversation, Agent $agent ) {
		try {
			$activated = $this->conversations->activate_if_new( $conversation->id );
			$this->conversations->assign_if_unassigned( $conversation->id, $agent->id );
			$updated = $this->conversations->find( $conversation->id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( ! $updated ) {
			return $this->not_found();
		}

		if ( $activated ) {
			$this->status_changed( $updated, Conversation::STATUS_NEW );
		}

		return $updated;
	}

	/**
	 * Lists conversations for the agent inbox.
	 *
	 * @param Actor                $actor Requesting actor; must be an active agent.
	 * @param array<string, mixed> $args  status ('' for all), search, page, per_page.
	 * @return array{items: ConversationSummary[], total: int, page: int, per_page: int, counts: array<string, int>}|\WP_Error
	 */
	public function inbox( Actor $actor, array $args = array() ) {
		if ( ! $this->policy->can_list_all( $actor ) ) {
			return $this->forbidden();
		}

		$status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : '';

		if ( '' !== $status && ! in_array( $status, Conversation::statuses(), true ) ) {
			return new \WP_Error(
				'chatchi_invalid_status',
				__( 'Unknown conversation status.', 'chatchi' ),
				array( 'status' => 400 )
			);
		}

		$search = isset( $args['search'] ) && is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		try {
			$page = $this->conversations->paginate(
				array(
					'status'   => $status,
					'search'   => mb_substr( $search, 0, self::SEARCH_MAX_LENGTH ),
					'page'     => isset( $args['page'] ) ? (int) $args['page'] : 1,
					'per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : 20,
				)
			);

			$page['items']  = $this->summarize( $page['items'] );
			$page['counts'] = $this->conversations->count_by_status();
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		return $page;
	}

	/**
	 * Adds visitor, agent, last message and unread count to conversations.
	 *
	 * Uses a fixed number of queries regardless of how many conversations
	 * are passed in.
	 *
	 * @param Conversation[] $conversations Conversations.
	 * @return ConversationSummary[]
	 *
	 * @throws DatabaseException When a lookup fails.
	 */
	public function summarize( array $conversations ): array {
		if ( ! $conversations ) {
			return array();
		}

		$ids              = array();
		$visitor_ids      = array();
		$agent_ids        = array();
		$last_message_ids = array();

		foreach ( $conversations as $conversation ) {
			$ids[]              = $conversation->id;
			$visitor_ids[]      = $conversation->visitor_id;
			$agent_ids[]        = $conversation->agent_id;
			$last_message_ids[] = $conversation->last_message_id;
		}

		$visitors = $this->visitors->find_many( $visitor_ids );
		$agents   = $this->agents->find_many( $agent_ids );
		$messages = $this->messages->find_many( $last_message_ids );
		$unread   = $this->messages->count_unread( $ids, Message::SENDER_VISITOR );

		$summaries = array();
		foreach ( $conversations as $conversation ) {
			$summaries[] = new ConversationSummary(
				$conversation,
				$visitors[ $conversation->visitor_id ] ?? null,
				$agents[ $conversation->agent_id ] ?? null,
				$messages[ $conversation->last_message_id ] ?? null,
				$unread[ $conversation->id ] ?? 0
			);
		}

		return $summaries;
	}

	/**
	 * Keeps a page URL only if it is an http(s) URL on this site.
	 *
	 * The URL comes from the browser and is shown to agents as a link, so
	 * anything else (other domains, javascript: URLs) is dropped.
	 *
	 * @param string $raw Raw URL.
	 */
	private function sanitize_page_url( string $raw ): string {
		$url = esc_url_raw( trim( $raw ), array( 'http', 'https' ) );

		if ( '' === $url ) {
			return '';
		}

		$host      = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || ! is_string( $site_host ) || strtolower( $host ) !== strtolower( $site_host ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * @param Conversation $conversation Conversation after the change.
	 * @param string       $old_status   Status before the change.
	 */
	private function status_changed( Conversation $conversation, string $old_status ): void {
		/**
		 * Fires after a conversation's status changes.
		 *
		 * @param Conversation $conversation Updated conversation.
		 * @param string       $old_status   Previous status.
		 * @param string       $new_status   New status.
		 */
		do_action( 'chatchi_conversation_status_changed', $conversation, $old_status, $conversation->status );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'chatchi_conversation_not_found',
			__( 'Conversation not found.', 'chatchi' ),
			array( 'status' => 404 )
		);
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error(
			'chatchi_forbidden',
			__( 'You are not allowed to do this.', 'chatchi' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Logs an unexpected failure and returns a safe, generic error.
	 *
	 * @param \Throwable $error Failure.
	 */
	private function failure( \Throwable $error ): \WP_Error {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Unexpected failures must reach the server log.
		error_log( '[ChatChi] Conversation error: ' . $error->getMessage() );

		return new \WP_Error(
			'chatchi_conversation_unavailable',
			__( 'Something went wrong. Please try again.', 'chatchi' ),
			array( 'status' => 500 )
		);
	}
}
