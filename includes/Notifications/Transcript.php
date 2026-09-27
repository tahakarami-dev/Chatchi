<?php
/**
 * Conversation transcripts by email.
 *
 * @package ChatChi
 */

namespace ChatChi\Notifications;

use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Core\Contracts\Module;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the visitor a copy of their conversation: automatically when it
 * ends (if turned on in the settings), or when an agent asks for it.
 *
 * Internal notes and events are never included. Everything from the
 * conversation is escaped; the email is simple right-to-left HTML.
 */
final class Transcript implements Module {

	/**
	 * Longest conversation sent in full, in messages.
	 */
	private const MAX_MESSAGES = 1000;

	private Settings $settings;

	private ConversationService $conversations;

	private MessageRepository $messages;

	private VisitorRepository $visitors;

	private AgentRepository $agents;

	/**
	 * @param Settings            $settings      Plugin settings.
	 * @param ConversationService $conversations Conversation access.
	 * @param MessageRepository   $messages      Message storage.
	 * @param VisitorRepository   $visitors      Visitor storage.
	 * @param AgentRepository     $agents        Agent storage, for names.
	 */
	public function __construct( Settings $settings, ConversationService $conversations, MessageRepository $messages, VisitorRepository $visitors, AgentRepository $agents ) {
		$this->settings      = $settings;
		$this->conversations = $conversations;
		$this->messages      = $messages;
		$this->visitors      = $visitors;
		$this->agents        = $agents;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'chatchi_conversation_closed', array( $this, 'on_close' ), 20 );
	}

	/**
	 * Sends the transcript when a conversation ends, if turned on.
	 *
	 * @param Conversation $conversation Closed conversation.
	 */
	public function on_close( Conversation $conversation ): void {
		if ( ! $this->settings->get( 'transcript_enabled' ) ) {
			return;
		}

		$sent = $this->send( $conversation );

		if ( $sent instanceof \WP_Error && 'chatchi_transcript_failed' === $sent->get_error_code() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Mail failures must reach the server log.
			error_log( '[ChatChi] Transcript email for conversation ' . $conversation->id . ' could not be sent.' );
		}
	}

	/**
	 * An agent asks to email the transcript now.
	 *
	 * @param Actor $actor Requesting agent.
	 * @param int   $id    Conversation ID.
	 * @return string|\WP_Error The address it was sent to.
	 */
	public function send_for( Actor $actor, int $id ) {
		if ( ! $actor->is_agent() ) {
			return new \WP_Error( 'chatchi_forbidden', __( 'You are not allowed to do this.', 'chatchi' ), array( 'status' => 403 ) );
		}

		$conversation = $this->conversations->get( $actor, $id );

		return $conversation instanceof \WP_Error ? $conversation : $this->send( $conversation );
	}

	/**
	 * Emails the transcript to the conversation's visitor.
	 *
	 * @param Conversation $conversation Conversation.
	 * @return string|\WP_Error The address it was sent to.
	 */
	public function send( Conversation $conversation ) {
		try {
			$visitor  = $this->visitors->find( $conversation->visitor_id );
			$messages = $this->load( $conversation->id );
			$agents   = $this->agents->find_many( $this->agent_ids( $messages ) );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'Transcript', 'chatchi_transcript_failed' );
		}

		if ( ! $visitor || ! is_email( $visitor->email ) ) {
			return new \WP_Error( 'chatchi_transcript_no_email', __( 'This visitor has not given an email address.', 'chatchi' ), array( 'status' => 400 ) );
		}

		if ( ! $messages ) {
			return new \WP_Error( 'chatchi_transcript_empty', __( 'There are no messages to send yet.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		$email = array(
			'to'      => $visitor->email,
			/* translators: %s: site name. */
			'subject' => sprintf( __( 'Your conversation with %s', 'chatchi' ), $site ),
			'message' => $this->html( $conversation, $messages, $agents, $visitor->name, $site ),
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);

		/**
		 * Filters the transcript email. Return a falsy value to send nothing.
		 *
		 * @param array        $email        to, subject, message (HTML), headers.
		 * @param Conversation $conversation The conversation.
		 */
		$email = apply_filters( 'chatchi_transcript_email', $email, $conversation );

		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return new \WP_Error( 'chatchi_transcript_cancelled', __( 'Sending the transcript was cancelled.', 'chatchi' ), array( 'status' => 400 ) );
		}

		if ( ! wp_mail( $email['to'], (string) $email['subject'], (string) $email['message'], (array) ( $email['headers'] ?? array() ) ) ) {
			return new \WP_Error( 'chatchi_transcript_failed', __( 'The email could not be sent. Check that your site can send email.', 'chatchi' ), array( 'status' => 502 ) );
		}

		return (string) $email['to'];
	}

	/**
	 * The conversation's messages for the visitor, oldest first.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return Message[]
	 */
	private function load( int $conversation_id ): array {
		$all   = array();
		$after = 0;

		do {
			$page = $this->messages->list_for_conversation( $conversation_id, $after, 0, 100 );
			$all  = array_merge( $all, $page );

			if ( $page ) {
				$after = end( $page )->id;
			}
		} while ( count( $page ) === 100 && count( $all ) < self::MAX_MESSAGES );

		return $all;
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
	 * The email body.
	 *
	 * @param Conversation                            $conversation Conversation.
	 * @param Message[]                               $messages     Messages.
	 * @param array<int, \ChatChi\Models\Agent>        $agents       Agents by ID.
	 * @param string                                  $visitor_name Visitor's name.
	 * @param string                                  $site         Site name.
	 */
	private function html( Conversation $conversation, array $messages, array $agents, string $visitor_name, string $site ): string {
		$rtl   = is_rtl();
		$dir   = $rtl ? 'rtl' : 'ltr';
		$align = $rtl ? 'right' : 'left';
		$you   = '' !== $visitor_name ? $visitor_name : __( 'You', 'chatchi' );
		$rows  = '';

		foreach ( $messages as $message ) {
			if ( Message::SENDER_SYSTEM === $message->sender_type ) {
				continue;
			}

			$mine   = Message::SENDER_VISITOR === $message->sender_type;
			$sender = $mine ? $you : ( isset( $agents[ $message->sender_id ] ) ? $agents[ $message->sender_id ]->display_name : __( 'Support', 'chatchi' ) );
			$time   = wp_date( (string) get_option( 'time_format' ), (int) strtotime( $message->created_at . ' UTC' ) );
			$body   = $message->attachment_id > 0 ? '📎 ' . $message->body : $message->body;

			$rows .= sprintf(
				'<tr><td style="padding:10px 14px;border-bottom:1px solid #eeeef3;text-align:%1$s;"><div style="font-size:12px;color:%2$s;margin-bottom:4px;"><strong>%3$s</strong> · %4$s</div><div style="font-size:14px;line-height:1.8;color:#1a1a2e;unicode-bidi:plaintext;">%5$s</div></td></tr>',
				esc_attr( $align ),
				$mine ? '#6b6b80' : '#5b5bd6',
				esc_html( $sender ),
				esc_html( $time ),
				nl2br( esc_html( $body ) )
			);
		}

		$date = wp_date( (string) get_option( 'date_format' ), (int) strtotime( $conversation->created_at . ' UTC' ) );

		return sprintf(
			'<!doctype html><html dir="%1$s"><body style="margin:0;padding:24px;background:#f7f7fa;font-family:Tahoma,Arial,sans-serif;direction:%1$s;"><table role="presentation" width="100%%" style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;border-collapse:collapse;overflow:hidden;"><tr><td style="padding:18px 14px;background:#5b5bd6;color:#fff;text-align:%2$s;"><div style="font-size:16px;font-weight:bold;">%3$s</div><div style="font-size:12px;opacity:.9;">%4$s</div></td></tr>%5$s<tr><td style="padding:14px;font-size:12px;color:#8a8a9e;text-align:%2$s;">%6$s</td></tr></table></body></html>',
			esc_attr( $dir ),
			esc_attr( $align ),
			/* translators: %s: site name. */
			esc_html( sprintf( __( 'Your conversation with %s', 'chatchi' ), $site ) ),
			esc_html( $date ),
			$rows,
			esc_html__( 'This is a copy of your chat, for your records.', 'chatchi' )
		);
	}
}
