<?php
/**
 * SMS to visitors who left before an agent replied.
 *
 * @package ChatChi
 */

namespace ChatChi\Notifications;

use ChatChi\Conversations\Actor;
use ChatChi\Core\Contracts\Module;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;
use ChatChi\Visitors\ProfileValidator;

defined( 'ABSPATH' ) || exit;

/**
 * When an agent replies, checks again after the set delay: if the visitor
 * still has not read the reply and is not on the site, they get one SMS
 * with a link back to the page they chatted on (the link opens the chat).
 *
 * Only visitors who gave a mobile number get an SMS, at most one per
 * conversation every few hours. Sending runs in WP-Cron, never while the
 * agent waits for their message to be sent.
 */
final class SmsNotifier implements Module {

	public const HOOK = 'chatchi_sms_check';

	/**
	 * Visitors seen this recently are still on the site.
	 */
	private const ONLINE_SECONDS = 60;

	/**
	 * At most one SMS per conversation in this time.
	 */
	private const COOLDOWN = 6 * HOUR_IN_SECONDS;

	private Settings $settings;

	private ConversationRepository $conversations;

	private VisitorRepository $visitors;

	private MessageRepository $messages;

	private MessageService $events;

	private SmsGateway $gateway;

	private ProfileValidator $profile;

	/**
	 * @param Settings               $settings      Plugin settings.
	 * @param ConversationRepository $conversations Conversation storage.
	 * @param VisitorRepository      $visitors      Visitor storage.
	 * @param MessageRepository      $messages      Message storage, for unread replies.
	 * @param MessageService         $events        Records the SMS as an event for agents.
	 * @param SmsGateway             $gateway       SMS services.
	 * @param ProfileValidator       $profile       Mobile number rules.
	 */
	public function __construct( Settings $settings, ConversationRepository $conversations, VisitorRepository $visitors, MessageRepository $messages, MessageService $events, SmsGateway $gateway, ProfileValidator $profile ) {
		$this->settings      = $settings;
		$this->conversations = $conversations;
		$this->visitors      = $visitors;
		$this->messages      = $messages;
		$this->events        = $events;
		$this->gateway       = $gateway;
		$this->profile       = $profile;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'chatchi_message_sent', array( $this, 'on_message' ), 40, 3 );
		add_action( self::HOOK, array( $this, 'check' ) );
	}

	/**
	 * Schedules a check after an agent's reply.
	 *
	 * @param Message      $message      New message.
	 * @param Conversation $conversation Its conversation.
	 * @param Actor        $actor        Sender.
	 */
	public function on_message( Message $message, Conversation $conversation, Actor $actor ): void {
		if ( ! $actor->is_agent() || $message->internal || ! $this->settings->get( 'sms_enabled' ) ) {
			return;
		}

		$args = array( $conversation->id );

		if ( false === wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + 60 * max( 1, (int) $this->settings->get( 'sms_delay' ) ), self::HOOK, $args );
		}
	}

	/**
	 * The delayed check: sends the SMS when it is still useful.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool Whether an SMS was sent.
	 */
	public function check( $conversation_id ): bool {
		$conversation_id = (int) $conversation_id;

		if ( $conversation_id <= 0 || ! $this->settings->get( 'sms_enabled' ) || get_transient( $this->cooldown_key( $conversation_id ) ) ) {
			return false;
		}

		try {
			$conversation = $this->conversations->find( $conversation_id );
			$visitor      = $conversation ? $this->visitors->find( $conversation->visitor_id ) : null;
			$unread       = $conversation ? ( $this->messages->count_unread( array( $conversation->id ), Message::SENDER_AGENT )[ $conversation->id ] ?? 0 ) : 0;
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'SMS', 'chatchi_unavailable' );
			return false;
		}

		if ( ! $conversation || ! $visitor || $unread < 1 ) {
			return false;
		}

		// Still on the site: the widget will show the reply.
		$seen = strtotime( $visitor->last_seen_at . ' UTC' );
		if ( $seen && time() - $seen < self::ONLINE_SECONDS ) {
			return false;
		}

		$phone = '' !== $visitor->phone ? $this->profile->normalize_phone( $visitor->phone ) : null;

		if ( null === $phone ) {
			return false;
		}

		$text = $this->text( $conversation );

		/**
		 * Filters the SMS to a visitor. Return an empty string to send nothing.
		 *
		 * @param string       $text         Message.
		 * @param Conversation $conversation The conversation.
		 * @param string       $phone        Mobile number.
		 */
		$text = (string) apply_filters( 'chatchi_sms_message', $text, $conversation, $phone );

		if ( '' === trim( $text ) ) {
			return false;
		}

		// Claimed before sending, so two overlapping cron runs send once.
		set_transient( $this->cooldown_key( $conversation_id ), 1, self::COOLDOWN );

		$sent = $this->gateway->send( (string) $this->settings->get( 'sms_provider' ), $this->credentials(), $phone, $text );

		if ( $sent instanceof \WP_Error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failed SMS must reach the server log.
			error_log( '[ChatChi] SMS for conversation ' . $conversation_id . ' failed: ' . $sent->get_error_message() );
			/* translators: %s: reason. */
			$this->events->add_event( $conversation_id, sprintf( __( 'The SMS to the visitor could not be sent: %s', 'chatchi' ), $sent->get_error_message() ) );
			return false;
		}

		$this->events->add_event( $conversation_id, __( 'The visitor was not on the site; an SMS told them about the reply.', 'chatchi' ) );

		/**
		 * Fires after an SMS was sent to a visitor.
		 *
		 * @param Conversation $conversation The conversation.
		 * @param string       $phone        Mobile number.
		 */
		do_action( 'chatchi_sms_sent', $conversation, $phone );

		return true;
	}

	/**
	 * The configured text with {site} and {link} filled in.
	 *
	 * @param Conversation $conversation Conversation.
	 */
	private function text( Conversation $conversation ): string {
		$page = '' !== $conversation->page_url ? $conversation->page_url : home_url( '/' );
		$link = strtok( $page, '#' ) . '#chatchi';

		return strtr(
			$this->settings->sms_message(),
			array(
				'{site}' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'{link}' => $link,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function credentials(): array {
		return array(
			'api_key'  => (string) $this->settings->get( 'sms_api_key' ),
			'username' => (string) $this->settings->get( 'sms_username' ),
			'password' => (string) $this->settings->get( 'sms_password' ),
			'sender'   => (string) $this->settings->get( 'sms_sender' ),
		);
	}

	/**
	 * @param int $conversation_id Conversation ID.
	 */
	private function cooldown_key( int $conversation_id ): string {
		return 'chatchi_sms_' . $conversation_id;
	}
}
