<?php
/**
 * Message use cases.
 *
 * @package ChatChi
 */

namespace ChatChi\Messages;

use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Sending, reading and acknowledging messages.
 *
 * Access to the conversation is always checked through ConversationService,
 * so a visitor can never read or write another visitor's messages.
 * Methods return WP_Error instead of throwing.
 */
final class MessageService {

	private const DEFAULT_PAGE_SIZE = 30;

	private const MAX_PAGE_SIZE = 50;

	private ConversationService $conversations;

	private ConversationRepository $conversation_repository;

	private MessageRepository $messages;

	private AgentRepository $agents;

	private MessageBody $body;

	/**
	 * @param ConversationService    $conversations           Conversation access and rules.
	 * @param ConversationRepository $conversation_repository Conversation storage.
	 * @param MessageRepository      $messages                Message storage.
	 * @param AgentRepository        $agents                  Agent storage.
	 * @param MessageBody            $body                    Message text rules.
	 */
	public function __construct(
		ConversationService $conversations,
		ConversationRepository $conversation_repository,
		MessageRepository $messages,
		AgentRepository $agents,
		MessageBody $body
	) {
		$this->conversations           = $conversations;
		$this->conversation_repository = $conversation_repository;
		$this->messages                = $messages;
		$this->agents                  = $agents;
		$this->body                    = $body;
	}

	/**
	 * Sends a message into a conversation.
	 *
	 * Sending again with the same client ID returns the stored message and
	 * has no further effect, so network retries are safe. Replying also marks
	 * the other side's earlier messages as read.
	 *
	 * @param Actor       $actor           Sender.
	 * @param int         $conversation_id Conversation ID.
	 * @param mixed       $body            Raw message text.
	 * @param string|null $client_id       Optional client-generated UUID.
	 * @return Message|\WP_Error
	 */
	public function send( Actor $actor, int $conversation_id, $body, ?string $client_id = null ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$text = $this->body->clean( $body );

		if ( $text instanceof \WP_Error ) {
			return $text;
		}

		if ( null !== $client_id && ! wp_is_uuid( strtolower( $client_id ) ) ) {
			return new \WP_Error(
				'chatchi_invalid_client_id',
				__( 'Invalid message identifier.', 'chatchi' ),
				array( 'status' => 400 )
			);
		}

		try {
			if ( null !== $client_id ) {
				$existing = $this->messages->find_by_client_id( $conversation->id, $client_id );

				if ( $existing ) {
					return $existing;
				}
			}

			if ( $conversation->is_closed() ) {
				return new \WP_Error(
					'chatchi_conversation_closed',
					__( 'This conversation has been closed.', 'chatchi' ),
					array( 'status' => 409 )
				);
			}

			/**
			 * Lets other code refuse a message before it is stored, e.g. rate limiting.
			 *
			 * @param null|\WP_Error $error        Return a WP_Error to refuse the message.
			 * @param Actor          $actor        Sender.
			 * @param Conversation   $conversation Target conversation.
			 * @param string         $text         Clean message text.
			 */
			$refused = apply_filters( 'chatchi_pre_send_message', null, $actor, $conversation, $text );

			if ( $refused instanceof \WP_Error ) {
				return $refused;
			}

			$created = false;
			$message = $this->messages->create( $conversation->id, $this->sender_type( $actor ), $actor->id(), $text, $client_id, $created );

			if ( ! $created ) {
				return $message;
			}

			$this->conversation_repository->record_message( $conversation->id, $message->id, $message->created_at );
			$this->messages->mark_read( $conversation->id, $this->other_side( $actor ), $message->id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( $actor->is_agent() && null !== $actor->agent_model() ) {
			$updated = $this->conversations->mark_answered( $conversation, $actor->agent_model() );

			if ( ! $updated instanceof \WP_Error ) {
				$conversation = $updated;
			}
		}

		/**
		 * Fires after a new message is stored. Not fired for retried duplicates.
		 *
		 * @param Message      $message      New message.
		 * @param Conversation $conversation Its conversation.
		 * @param Actor        $actor        Sender.
		 */
		do_action( 'chatchi_message_sent', $message, $conversation, $actor );

		return $message;
	}

	/**
	 * Returns messages of a conversation the actor may see.
	 *
	 * - after_id > 0: newer messages, for polling.
	 * - otherwise: the latest messages, or those older than before_id.
	 *
	 * @param Actor                $actor           Reader.
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $args            after_id, before_id, limit.
	 * @return MessagePage|\WP_Error
	 */
	public function messages( Actor $actor, int $conversation_id, array $args = array() ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		$after_id  = max( 0, isset( $args['after_id'] ) ? (int) $args['after_id'] : 0 );
		$before_id = max( 0, isset( $args['before_id'] ) ? (int) $args['before_id'] : 0 );
		$limit     = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_PAGE_SIZE;
		$limit     = min( self::MAX_PAGE_SIZE, max( 1, $limit ) );

		try {
			// One extra row tells whether more messages exist.
			$messages = $this->messages->list_for_conversation( $conversation->id, $after_id, $before_id, $limit + 1 );
			$has_more = count( $messages ) > $limit;

			if ( $has_more ) {
				// Polling reads forwards, so the extra row is the newest; otherwise the oldest.
				$messages = $after_id > 0 ? array_slice( $messages, 0, $limit ) : array_slice( $messages, 1 );
			}

			$agents = $this->agents->find_many( $this->agent_ids( $messages ) );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		return new MessagePage( $messages, $agents, $has_more );
	}

	/**
	 * Marks the other side's messages as read by the actor.
	 *
	 * @param Actor $actor           Reader.
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $up_to_id        Only messages up to this ID; 0 for all.
	 * @return int|\WP_Error Number of messages marked.
	 */
	public function mark_read( Actor $actor, int $conversation_id, int $up_to_id = 0 ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		try {
			$count = $this->messages->mark_read( $conversation->id, $this->other_side( $actor ), max( 0, $up_to_id ) );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( $count > 0 ) {
			/**
			 * Fires after messages are marked as read.
			 *
			 * @param Conversation $conversation Conversation.
			 * @param Actor        $actor        Reader.
			 * @param int          $count        Messages marked.
			 */
			do_action( 'chatchi_messages_read', $conversation, $actor, $count );
		}

		return $count;
	}

	/**
	 * Counts the other side's messages the actor has not read.
	 *
	 * @param Actor $actor           Reader.
	 * @param int   $conversation_id Conversation ID.
	 * @return int|\WP_Error
	 */
	public function unread_count( Actor $actor, int $conversation_id ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		try {
			$counts = $this->messages->count_unread( array( $conversation->id ), $this->other_side( $actor ) );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		return $counts[ $conversation->id ] ?? 0;
	}

	/**
	 * @param Actor $actor Sender.
	 */
	private function sender_type( Actor $actor ): string {
		return $actor->is_agent() ? Message::SENDER_AGENT : Message::SENDER_VISITOR;
	}

	/**
	 * Returns whose messages the actor reads: agents read visitors and vice versa.
	 *
	 * @param Actor $actor Reader.
	 */
	private function other_side( Actor $actor ): string {
		return $actor->is_agent() ? Message::SENDER_VISITOR : Message::SENDER_AGENT;
	}

	/**
	 * @param Message[] $messages Messages.
	 * @return int[]
	 */
	private function agent_ids( array $messages ): array {
		$ids = array();

		foreach ( $messages as $message ) {
			if ( Message::SENDER_AGENT === $message->sender_type ) {
				$ids[] = $message->sender_id;
			}
		}

		return $ids;
	}

	/**
	 * Logs an unexpected failure and returns a safe, generic error.
	 *
	 * @param \Throwable $error Failure.
	 */
	private function failure( \Throwable $error ): \WP_Error {
		return Failure::report( $error, 'Message', 'chatchi_message_unavailable' );
	}
}
