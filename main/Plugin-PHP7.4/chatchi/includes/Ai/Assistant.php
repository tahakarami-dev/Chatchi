<?php
/**
 * The AI bot and reply suggestions.
 *
 * @package ChatChi
 */

namespace ChatChi\Ai;

use ChatChi\Availability\Availability;
use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Database\Repositories\AttachmentRepository;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Answers visitors with the configured AI while no person has taken the
 * conversation, and drafts replies for agents.
 *
 * The bot answers only when all of these hold: it is turned on; the
 * conversation is open, not assigned to a person and not handed over; the
 * latest visible message is the visitor's; and, in "offline" mode, nobody
 * is available. It hands the conversation to the team when the model ends
 * its answer with the handoff marker, when the visitor asks for a person,
 * or after the per-conversation reply limit. A daily limit caps the cost.
 *
 * Bot answers are stored as agent messages with sender ID 0, shown with
 * the bot's name.
 */
final class Assistant {

	/**
	 * A lock older than this belongs to a request that died.
	 */
	private const LOCK_SECONDS = 120;

	/**
	 * Today's number of AI requests: array( 'day' => Y-m-d, 'count' => n ).
	 */
	public const USAGE_OPTION = 'chatchi_ai_usage';

	private Settings $settings;

	private ConversationService $conversations;

	private ConversationRepository $repository;

	private MessageRepository $messages;

	private VisitorRepository $visitors;

	private MessageService $events;

	private Availability $availability;

	private AiClient $client;

	private Knowledge $knowledge;

	private ?AttachmentRepository $attachments;

	/**
	 * @param Settings               $settings      Plugin settings.
	 * @param ConversationService    $conversations Conversation access.
	 * @param ConversationRepository $repository    Conversation storage.
	 * @param MessageRepository      $messages      Message storage.
	 * @param VisitorRepository      $visitors      Visitor storage.
	 * @param MessageService         $events        Event lines for agents.
	 * @param Availability           $availability  Whether agents are online.
	 * @param AiClient               $client        The language model.
	 * @param Knowledge              $knowledge     Prompts.
	 * @param AttachmentRepository   $attachments   Files, to spot voice messages.
	 */
	public function __construct( Settings $settings, ConversationService $conversations, ConversationRepository $repository, MessageRepository $messages, VisitorRepository $visitors, MessageService $events, Availability $availability, AiClient $client, Knowledge $knowledge, ?AttachmentRepository $attachments = null ) {
		$this->settings      = $settings;
		$this->conversations = $conversations;
		$this->repository    = $repository;
		$this->messages      = $messages;
		$this->visitors      = $visitors;
		$this->events        = $events;
		$this->availability  = $availability;
		$this->client        = $client;
		$this->knowledge     = $knowledge;
		$this->attachments   = $attachments;
	}

	/**
	 * Whether the bot is turned on and configured.
	 */
	public function bot_enabled(): bool {
		return (bool) $this->settings->get( 'ai_bot_enabled' ) && '' !== (string) $this->settings->get( 'ai_api_key' );
	}

	/**
	 * Whether agents can ask for reply suggestions.
	 */
	public function suggestions_enabled(): bool {
		return (bool) $this->settings->get( 'ai_suggest_enabled' ) && '' !== (string) $this->settings->get( 'ai_api_key' );
	}

	/**
	 * Whether the bot still looks after a conversation (not handed over,
	 * not taken by a person).
	 *
	 * @param Conversation $conversation Conversation.
	 */
	public function is_active( Conversation $conversation ): bool {
		return $this->bot_enabled() && ! $conversation->is_closed() && 0 === $conversation->agent_id && null === $conversation->bot_handoff_at;
	}

	/**
	 * The visitor just wrote: the bot answers if it should.
	 *
	 * @param Actor $actor The visitor.
	 * @param int   $id    Conversation ID.
	 * @return array{answered: bool, handoff: bool}|\WP_Error
	 */
	public function answer( Actor $actor, int $id ) {
		$idle = array(
			'answered' => false,
			'handoff'  => false,
		);

		$conversation = $this->conversations->get( $actor, $id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $actor->is_visitor() || ! $this->is_active( $conversation ) ) {
			return $idle;
		}

		if ( 'offline' === $this->settings->get( 'ai_bot_mode' ) && 'online' === ( $this->availability->state()['mode'] ?? '' ) ) {
			return $idle;
		}

		if ( ! $this->lock( $conversation->id ) ) {
			return $idle;
		}

		try {
			return $this->reply( $conversation );
		} finally {
			$this->unlock( $conversation->id );
		}
	}

	/**
	 * The visitor asks for a person.
	 *
	 * @param Actor $actor The visitor.
	 * @param int   $id    Conversation ID.
	 * @return Conversation|\WP_Error
	 */
	public function request_person( Actor $actor, int $id ) {
		$conversation = $this->conversations->get( $actor, $id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( $actor->is_visitor() && null === $conversation->bot_handoff_at ) {
			$this->hand_off( $conversation, __( 'The visitor asked to talk to a person.', 'chatchi' ) );
		}

		return $this->repository->find( $conversation->id ) ?? $conversation;
	}

	/**
	 * A draft reply for an agent.
	 *
	 * @param Actor $actor The agent.
	 * @param int   $id    Conversation ID.
	 * @return string|\WP_Error
	 */
	public function suggest( Actor $actor, int $id ) {
		if ( ! $actor->is_agent() ) {
			return new \WP_Error( 'chatchi_forbidden', __( 'You are not allowed to do this.', 'chatchi' ), array( 'status' => 403 ) );
		}

		if ( ! $this->suggestions_enabled() ) {
			return new \WP_Error( 'chatchi_ai_disabled', __( 'Reply suggestions are turned off.', 'chatchi' ), array( 'status' => 403 ) );
		}

		$conversation = $this->conversations->get( $actor, $id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $this->within_daily_limit() ) {
			return new \WP_Error( 'chatchi_ai_limit', __( "Today's AI limit has been reached. It resets tomorrow; the limit is in Settings > AI.", 'chatchi' ), array( 'status' => 429 ) );
		}

		try {
			$turns   = $this->knowledge->turns( $this->messages->list_for_conversation( $conversation->id, 0, 0, 50 ) );
			$visitor = $this->visitors->find( $conversation->visitor_id );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'AI', 'chatchi_unavailable' );
		}

		if ( ! $turns ) {
			return new \WP_Error( 'chatchi_ai_empty', __( 'There is no visitor message to reply to yet.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$agent = $actor->agent_model();
		$this->count_use();

		$text = $this->client->complete( $this->config(), $this->knowledge->suggestion_prompt( $agent ? $agent->display_name : '' ), $this->knowledge->visitor_context( $visitor ), $turns );

		return $text instanceof \WP_Error ? $text : trim( str_replace( Knowledge::HANDOFF, '', $text ) );
	}

	/**
	 * Asks the model and stores its answer.
	 *
	 * @param Conversation $conversation Conversation.
	 * @return array{answered: bool, handoff: bool}
	 */
	private function reply( Conversation $conversation ): array {
		$result = array(
			'answered' => false,
			'handoff'  => false,
		);

		try {
			$history = $this->messages->list_for_conversation( $conversation->id, 0, 0, 50 );
			$visitor = $this->visitors->find( $conversation->visitor_id );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'AI', 'chatchi_unavailable' );
			return $result;
		}

		$last = end( $history );

		// Only an unanswered visitor message gets an answer.
		if ( ! $last instanceof Message || Message::SENDER_VISITOR !== $last->sender_type ) {
			return $result;
		}

		// The bot cannot listen to voice messages: a person answers them.
		if ( $last->attachment_id > 0 && $this->is_voice( $last->attachment_id ) ) {
			$this->hand_off( $conversation, __( 'The visitor sent a voice message, so the bot handed the conversation to the team.', 'chatchi' ) );
			$result['handoff'] = true;
			return $result;
		}

		$bot_replies = count(
			array_filter(
				$history,
				static function ( Message $message ): bool {
					return Message::SENDER_AGENT === $message->sender_type && 0 === $message->sender_id;
				}
			)
		);

		if ( $bot_replies >= (int) $this->settings->get( 'ai_max_replies' ) ) {
			$this->hand_off( $conversation, __( 'The bot reached its reply limit for this conversation.', 'chatchi' ) );
			$result['handoff'] = true;
			return $result;
		}

		if ( ! $this->within_daily_limit() ) {
			return $result;
		}

		$turns = $this->knowledge->turns( $history );

		if ( ! $turns ) {
			return $result;
		}

		$this->count_use();

		$text = $this->client->complete( $this->config(), $this->knowledge->bot_prompt(), $this->knowledge->visitor_context( $visitor ), $turns );

		if ( $text instanceof \WP_Error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- AI failures must reach the server log.
			error_log( '[ChatChi] AI bot for conversation ' . $conversation->id . ': ' . $text->get_error_message() );
			/* translators: %s: reason. */
			$this->hand_off( $conversation, sprintf( __( 'The bot could not answer (%s) and handed the conversation to the team.', 'chatchi' ), $text->get_error_message() ) );
			$result['handoff'] = true;
			return $result;
		}

		$handoff = false !== strpos( $text, Knowledge::HANDOFF );
		$text    = trim( str_replace( Knowledge::HANDOFF, '', $text ) );

		/**
		 * Filters the bot's answer before it is sent. Return an empty string to send nothing.
		 *
		 * @param string       $text         Answer.
		 * @param Conversation $conversation The conversation.
		 */
		$text = trim( (string) apply_filters( 'chatchi_bot_answer', $text, $conversation ) );

		if ( '' !== $text && $this->post( $conversation, $text ) ) {
			$result['answered'] = true;
		}

		if ( $handoff ) {
			$this->hand_off( $conversation, __( 'The bot handed the conversation to the team.', 'chatchi' ) );
			$result['handoff'] = true;
		}

		return $result;
	}

	/**
	 * Whether an attachment is a voice message.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function is_voice( int $attachment_id ): bool {
		if ( ! $this->attachments ) {
			return false;
		}

		try {
			$attachment = $this->attachments->find( $attachment_id );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $attachment instanceof \ChatChi\Models\Attachment && $attachment->is_audio();
	}

	/**
	 * Stores the bot's answer as an agent message with sender ID 0.
	 *
	 * @param Conversation $conversation Conversation.
	 * @param string       $text         Answer.
	 */
	private function post( Conversation $conversation, string $text ): bool {
		try {
			// A person may have closed or taken the chat while the model was thinking.
			$fresh = $this->repository->find( $conversation->id );

			if ( ! $fresh || $fresh->is_closed() || $fresh->agent_id > 0 ) {
				return false;
			}

			$created = false;
			$message = $this->messages->create( $conversation->id, Message::SENDER_AGENT, 0, $text, null, $created );
			$this->repository->record_message( $conversation->id, $message->id, $message->created_at );
			$this->messages->mark_read( $conversation->id, Message::SENDER_VISITOR, $message->id );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'AI', 'chatchi_unavailable' );
			return false;
		}

		/**
		 * Fires after the AI bot answered a visitor.
		 *
		 * @param Message      $message      The answer.
		 * @param Conversation $conversation The conversation.
		 */
		do_action( 'chatchi_bot_replied', $message, $conversation );

		return true;
	}

	/**
	 * Hands the conversation to the team; later visitor messages wait for a person.
	 *
	 * @param Conversation $conversation Conversation.
	 * @param string       $reason       Event line for agents.
	 */
	private function hand_off( Conversation $conversation, string $reason ): void {
		try {
			if ( ! $this->repository->set_bot_handoff( $conversation->id ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'AI', 'chatchi_unavailable' );
			return;
		}

		$this->events->add_event( $conversation->id, $reason );

		/**
		 * Fires after the AI bot handed a conversation to the team.
		 *
		 * @param Conversation $conversation The conversation.
		 */
		do_action( 'chatchi_bot_handoff', $conversation );
	}

	/**
	 * @return array<string, string>
	 */
	private function config(): array {
		return array(
			'provider' => (string) $this->settings->get( 'ai_provider' ),
			'api_key'  => (string) $this->settings->get( 'ai_api_key' ),
			'base_url' => (string) $this->settings->get( 'ai_base_url' ),
			'model'    => (string) $this->settings->get( 'ai_model' ),
		);
	}

	private function within_daily_limit(): bool {
		return $this->used_today() < (int) $this->settings->get( 'ai_daily_limit' );
	}

	/**
	 * AI requests made today (site time), for the daily limit.
	 */
	public function used_today(): int {
		$usage = get_option( self::USAGE_OPTION );

		return is_array( $usage ) && ( $usage['day'] ?? '' ) === wp_date( 'Y-m-d' ) ? (int) ( $usage['count'] ?? 0 ) : 0;
	}

	/**
	 * Counts one AI request against today's limit.
	 */
	private function count_use(): void {
		update_option(
			self::USAGE_OPTION,
			array(
				'day'   => wp_date( 'Y-m-d' ),
				'count' => $this->used_today() + 1,
			),
			false
		);
	}

	/**
	 * One answer at a time per conversation. add_option() fails when the
	 * option exists, which makes it a lock across requests.
	 *
	 * @param int $id Conversation ID.
	 */
	private function lock( int $id ): bool {
		$key = 'chatchi_bot_lock_' . $id;

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( $key, 0 ) > self::LOCK_SECONDS ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}

		return false;
	}

	/**
	 * @param int $id Conversation ID.
	 */
	private function unlock( int $id ): void {
		delete_option( 'chatchi_bot_lock_' . $id );
	}
}
