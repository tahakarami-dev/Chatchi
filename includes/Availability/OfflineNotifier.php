<?php
/**
 * Email about messages nobody is there to answer.
 *
 * @package ChatChi
 */

namespace ChatChi\Availability;

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
 * Emails the team when a visitor starts a conversation while support is
 * away or offline, so the message is not missed.
 *
 * Only the first visitor message of a conversation is sent, which keeps
 * this to one email per conversation.
 */
final class OfflineNotifier implements Module {

	private Availability $availability;

	private Settings $settings;

	private MessageRepository $messages;

	private VisitorRepository $visitors;

	/**
	 * @param Availability      $availability Whether support is available.
	 * @param Settings          $settings     Plugin settings, for the recipient.
	 * @param MessageRepository $messages     Message storage.
	 * @param VisitorRepository $visitors     Visitor storage, for contact details.
	 */
	public function __construct( Availability $availability, Settings $settings, MessageRepository $messages, VisitorRepository $visitors ) {
		$this->availability = $availability;
		$this->settings     = $settings;
		$this->messages     = $messages;
		$this->visitors     = $visitors;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'chatchi_message_sent', array( $this, 'maybe_notify' ), 20, 3 );
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

		$to = $this->settings->notify_email();

		if ( '' === $to || Availability::ONLINE === $this->availability->mode() ) {
			return;
		}

		try {
			if ( $this->messages->count_from( $conversation->id, Message::SENDER_VISITOR ) !== 1 ) {
				return;
			}

			$visitor = $this->visitors->find( $conversation->visitor_id );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Notification', 'chatchi_unavailable' );
			return;
		}

		$name = $visitor && '' !== $visitor->name ? $visitor->name : __( 'A visitor', 'chatchi' );
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		/* translators: 1: site name, 2: visitor name. */
		$subject = sprintf( __( '[%1$s] New chat message from %2$s', 'chatchi' ), $site, $name );

		$lines = array(
			/* translators: %s: visitor name. */
			sprintf( __( '%s sent a message while nobody was available in the chat:', 'chatchi' ), $name ),
			'',
			$message->body,
			'',
		);

		if ( $visitor && '' !== $visitor->email ) {
			/* translators: %s: email address. */
			$lines[] = sprintf( __( 'Email: %s', 'chatchi' ), $visitor->email );
		}

		if ( $visitor && '' !== $visitor->phone ) {
			/* translators: %s: phone number. */
			$lines[] = sprintf( __( 'Mobile: %s', 'chatchi' ), $visitor->phone );
		}

		if ( '' !== $conversation->page_url ) {
			/* translators: %s: page address. */
			$lines[] = sprintf( __( 'Page: %s', 'chatchi' ), $conversation->page_url );
		}

		$lines[] = '';
		/* translators: %s: link to the conversation in the agent panel. */
		$lines[] = sprintf( __( 'Answer in the chat panel: %s', 'chatchi' ), admin_url( 'admin.php?page=chatchi#/conversations/' . $conversation->id ) );

		$email = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => implode( "\n", $lines ),
			'headers' => $visitor && is_email( $visitor->email ) ? array( 'Reply-To: ' . $this->reply_to( $name, $visitor->email ) ) : array(),
		);

		/**
		 * Filters the email about a message sent while support was unavailable.
		 * Return a falsy value to send nothing.
		 *
		 * @param array        $email        to, subject, message, headers.
		 * @param Message      $message      The message.
		 * @param Conversation $conversation Its conversation.
		 */
		$email = apply_filters( 'chatchi_offline_email', $email, $message, $conversation );

		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return;
		}

		wp_mail( $email['to'], (string) $email['subject'], (string) $email['message'], (array) ( $email['headers'] ?? array() ) );
	}

	/**
	 * A Reply-To value that cannot inject headers.
	 *
	 * @param string $name  Visitor name.
	 * @param string $email Visitor email.
	 */
	private function reply_to( string $name, string $email ): string {
		$name = trim( str_replace( array( "\r", "\n", '"', '<', '>' ), ' ', $name ) );

		return '' !== $name ? sprintf( '"%s" <%s>', $name, $email ) : $email;
	}
}
