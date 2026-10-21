<?php
/**
 * Visitor ratings of ended conversations.
 *
 * @package ChatChi
 */

namespace ChatChi\Conversations;

use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Conversation;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a visitor rate their own conversation from 1 to 5 stars, with an
 * optional comment, once it has ended.
 *
 * A rating may be changed for a while after the chat ends (a mis-tap is
 * easy on a phone); older conversations cannot be rated any more.
 */
final class RatingService {

	public const COMMENT_MAX_LENGTH = 500;

	/**
	 * How long after a conversation ends it can still be rated, in seconds.
	 */
	private const WINDOW = 7 * DAY_IN_SECONDS;

	private Settings $settings;

	private ConversationService $conversations;

	private ConversationRepository $repository;

	private MessageService $messages;

	/**
	 * @param Settings               $settings      Plugin settings.
	 * @param ConversationService    $conversations Conversation access.
	 * @param ConversationRepository $repository    Conversation storage.
	 * @param MessageService         $messages      For the event line agents see.
	 */
	public function __construct( Settings $settings, ConversationService $conversations, ConversationRepository $repository, MessageService $messages ) {
		$this->settings      = $settings;
		$this->conversations = $conversations;
		$this->repository    = $repository;
		$this->messages      = $messages;
	}

	/**
	 * Whether visitors are asked to rate chats.
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'rating_enabled' );
	}

	/**
	 * Records the visitor's rating.
	 *
	 * @param Actor  $actor   The visitor.
	 * @param int    $id      Conversation ID.
	 * @param int    $score   1–5.
	 * @param string $comment Optional comment.
	 * @return Conversation|\WP_Error The rated conversation.
	 */
	public function rate( Actor $actor, int $id, int $score, string $comment ) {
		if ( ! $actor->is_visitor() ) {
			return new \WP_Error( 'chatchi_forbidden', __( 'You are not allowed to do this.', 'chatchi' ), array( 'status' => 403 ) );
		}

		if ( ! $this->enabled() ) {
			return new \WP_Error( 'chatchi_rating_disabled', __( 'Rating is turned off.', 'chatchi' ), array( 'status' => 403 ) );
		}

		if ( $score < 1 || $score > 5 ) {
			return new \WP_Error( 'chatchi_rating_invalid', __( 'Choose from 1 to 5 stars.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$comment = PlainText::paragraphs( $comment );

		if ( mb_strlen( $comment ) > self::COMMENT_MAX_LENGTH ) {
			/* translators: %d: maximum characters. */
			return new \WP_Error( 'chatchi_rating_invalid', sprintf( __( 'The comment can be at most %d characters.', 'chatchi' ), self::COMMENT_MAX_LENGTH ), array( 'status' => 400 ) );
		}

		$conversation = $this->conversations->get( $actor, $id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( ! $conversation->is_closed() ) {
			return new \WP_Error( 'chatchi_rating_open', __( 'You can rate the chat once it has ended.', 'chatchi' ), array( 'status' => 409 ) );
		}

		$ended = $conversation->closed_at ? (int) strtotime( $conversation->closed_at . ' UTC' ) : 0;

		if ( $ended > 0 && time() - $ended > self::WINDOW ) {
			return new \WP_Error( 'chatchi_rating_expired', __( 'This chat can no longer be rated.', 'chatchi' ), array( 'status' => 409 ) );
		}

		$first = null === $conversation->rating;

		try {
			$this->repository->set_rating( $conversation->id, $score, $comment );
			$rated = $this->repository->find( $conversation->id );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'Rating', 'chatchi_unavailable' );
		}

		if ( ! $rated ) {
			return new \WP_Error( 'chatchi_conversation_not_found', __( 'Conversation not found.', 'chatchi' ), array( 'status' => 404 ) );
		}

		$this->messages->add_event(
			$rated->id,
			sprintf(
				/* translators: 1: stars, e.g. ★★★★☆, 2: the visitor's comment or nothing. */
				$first ? __( 'The visitor rated this chat %1$s%2$s', 'chatchi' ) : __( 'The visitor changed their rating to %1$s%2$s', 'chatchi' ),
				str_repeat( '★', $score ) . str_repeat( '☆', 5 - $score ),
				'' !== $comment ? ': ' . $comment : ''
			)
		);

		/**
		 * Fires after a visitor rates a conversation.
		 *
		 * @param Conversation $conversation Rated conversation (rating, rating_comment).
		 */
		do_action( 'chatchi_conversation_rated', $rated );

		return $rated;
	}
}
