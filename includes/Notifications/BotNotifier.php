<?php
/**
 * Telegram and Bale messages about new chats.
 *
 * @package ChatChi
 */

namespace ChatChi\Notifications;

use ChatChi\Availability\Availability;
use ChatChi\Conversations\Actor;
use ChatChi\Core\Contracts\Module;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a message to a Telegram or Bale chat (a person, group or channel)
 * when a visitor starts a conversation, so the team hears about it on
 * their phones. Bale's bot API is the same as Telegram's.
 *
 * Messages are sent without waiting for the answer, so visitors are never
 * slowed down; the "send a test" button in the settings waits and reports
 * what the service said.
 */
final class BotNotifier implements Module {

	public const SERVICES = array( 'telegram', 'bale' );

	private const API = array(
		'telegram' => 'https://api.telegram.org',
		'bale'     => 'https://tapi.bale.ai',
	);

	private Settings $settings;

	private Availability $availability;

	private MessageRepository $messages;

	private VisitorRepository $visitors;

	/**
	 * @param Settings          $settings     Plugin settings.
	 * @param Availability      $availability Whether support is available.
	 * @param MessageRepository $messages     Message storage.
	 * @param VisitorRepository $visitors     Visitor storage.
	 */
	public function __construct( Settings $settings, Availability $availability, MessageRepository $messages, VisitorRepository $visitors ) {
		$this->settings     = $settings;
		$this->availability = $availability;
		$this->messages     = $messages;
		$this->visitors     = $visitors;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'chatchi_message_sent', array( $this, 'maybe_notify' ), 30, 3 );
	}

	/**
	 * Services that are switched on and filled in.
	 *
	 * @return string[]
	 */
	public function active_services(): array {
		$active = array();

		foreach ( self::SERVICES as $service ) {
			if ( $this->settings->get( $service . '_enabled' ) && '' !== $this->settings->get( $service . '_token' ) && '' !== $this->settings->get( $service . '_chat_id' ) ) {
				$active[] = $service;
			}
		}

		return $active;
	}

	/**
	 * @param Message      $message      New message.
	 * @param Conversation $conversation Its conversation.
	 * @param Actor        $actor        Sender.
	 */
	public function maybe_notify( Message $message, Conversation $conversation, Actor $actor ): void {
		if ( ! $actor->is_visitor() ) {
			return;
		}

		$services = $this->active_services();

		if ( ! $services ) {
			return;
		}

		if ( 'offline' === $this->settings->get( 'bot_notify' ) && Availability::ONLINE === $this->availability->mode() ) {
			return;
		}

		try {
			// One message per conversation: its first visitor message.
			if ( $this->messages->count_from( $conversation->id, Message::SENDER_VISITOR ) !== 1 ) {
				return;
			}

			$visitor = $this->visitors->find( $conversation->visitor_id );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Notification', 'chatchi_unavailable' );
			return;
		}

		$contact = array_filter(
			array(
				$visitor && '' !== $visitor->name ? $visitor->name : __( 'A visitor', 'chatchi' ),
				$visitor ? $visitor->email : '',
				$visitor ? $visitor->phone : '',
			)
		);

		$lines = array(
			/* translators: %s: site name. */
			'💬 ' . sprintf( __( 'New chat on %s', 'chatchi' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			'👤 ' . implode( ' · ', $contact ),
			'',
			mb_strlen( $message->body ) > 600 ? mb_substr( $message->body, 0, 599 ) . '…' : $message->body,
			'',
			'🔗 ' . admin_url( 'admin.php?page=chatchi#/conversations/' . $conversation->id ),
		);

		/**
		 * Filters the Telegram / Bale message about a new chat. Return an
		 * empty string to send nothing.
		 *
		 * @param string       $text         Message text.
		 * @param Message      $message      The visitor's first message.
		 * @param Conversation $conversation Its conversation.
		 */
		$text = (string) apply_filters( 'chatchi_bot_message', implode( "\n", $lines ), $message, $conversation );

		if ( '' === $text ) {
			return;
		}

		foreach ( $services as $service ) {
			$this->send( $service, (string) $this->settings->get( $service . '_token' ), (string) $this->settings->get( $service . '_chat_id' ), $text, false );
		}
	}

	/**
	 * Sends a message through a bot.
	 *
	 * @param string $service  "telegram" or "bale".
	 * @param string $token    Bot token.
	 * @param string $chat_id  Chat ID or @channel.
	 * @param string $text     Plain text.
	 * @param bool        $blocking Wait for the answer and report it.
	 * @param string|null $api_url  Telegram relay to use instead of the saved one (for tests).
	 * @return true|\WP_Error
	 */
	public function send( string $service, string $token, string $chat_id, string $text, bool $blocking, ?string $api_url = null ) {
		$url = $this->api_url( $service, $api_url ) . '/bot' . $token . '/sendMessage';

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => $blocking ? 10 : 3,
				'blocking' => $blocking,
				'body'     => array(
					'chat_id'                  => $chat_id,
					'text'                     => $text,
					'disable_web_page_preview' => 'true',
				),
			)
		);

		if ( ! $blocking ) {
			return true;
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'chatchi_bot_unreachable',
				/* translators: %s: technical error from the connection. */
				sprintf( __( 'Could not reach the service: %s', 'chatchi' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			$reason = is_array( $body ) && ! empty( $body['description'] ) ? (string) $body['description'] : 'HTTP ' . wp_remote_retrieve_response_code( $response );

			return new \WP_Error(
				'chatchi_bot_refused',
				/* translators: %s: the service's own error message. */
				sprintf( __( 'The service refused the message: %s', 'chatchi' ), $reason ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * The bot API address: the official one, or the relay set in the
	 * settings (Telegram is unreachable from many servers in Iran).
	 *
	 * @param string      $service  "telegram" or "bale".
	 * @param string|null $override Relay to use instead of the saved one.
	 */
	private function api_url( string $service, ?string $override = null ): string {
		$custom = 'telegram' === $service ? ( null !== $override ? $override : (string) $this->settings->get( 'telegram_api_url' ) ) : '';
		$url    = '' !== $custom ? $custom : ( self::API[ $service ] ?? self::API['telegram'] );

		/**
		 * Filters a bot API address, e.g. to send through a relay.
		 *
		 * @param string $url     Address without a trailing slash.
		 * @param string $service "telegram" or "bale".
		 */
		return untrailingslashit( (string) apply_filters( 'chatchi_bot_api_url', $url, $service ) );
	}
}
