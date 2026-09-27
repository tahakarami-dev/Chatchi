<?php
/**
 * Widget interface text.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Every string the widget shows, translated on the server and handed to the
 * script, so translations live in the plugin's .po file like all others.
 */
final class WidgetStrings {

	/**
	 * @return array<string, string>
	 */
	public function all(): array {
		return array(
			'openChat'       => __( 'Open chat', 'chatchi' ),
			'closeChat'      => __( 'Close chat', 'chatchi' ),
			'title'          => __( 'Chat with us', 'chatchi' ),
			'subtitle'       => __( 'We usually reply within a few minutes', 'chatchi' ),
			'support'        => __( 'Support', 'chatchi' ),
			'endChat'        => __( 'End conversation', 'chatchi' ),
			'endConfirm'     => __( 'End this conversation?', 'chatchi' ),
			'endYes'         => __( 'End', 'chatchi' ),
			'endNo'          => __( 'Cancel', 'chatchi' ),
			'formIntro'      => __( 'Tell us a little about yourself so we can help you better.', 'chatchi' ),
			'name'           => __( 'Name', 'chatchi' ),
			'email'          => __( 'Email', 'chatchi' ),
			'phone'          => __( 'Mobile number', 'chatchi' ),
			'optional'       => __( 'optional', 'chatchi' ),
			'start'          => __( 'Start chat', 'chatchi' ),
			'placeholder'    => __( 'Write a message…', 'chatchi' ),
			'send'           => __( 'Send', 'chatchi' ),
			'empty'          => __( 'Ask us anything 👋', 'chatchi' ),
			'loading'        => __( 'Loading…', 'chatchi' ),
			'loadError'      => __( 'We could not connect to the chat.', 'chatchi' ),
			'retry'          => __( 'Try again', 'chatchi' ),
			'networkError'   => __( 'Connection problem. Check your internet and try again.', 'chatchi' ),
			'sendFailed'     => __( 'Not sent. Tap to retry.', 'chatchi' ),
			'sending'        => __( 'Sending…', 'chatchi' ),
			'sent'           => __( 'Sent', 'chatchi' ),
			'read'           => __( 'Read', 'chatchi' ),
			'earlier'        => __( 'Load earlier messages', 'chatchi' ),
			'closed'         => __( 'This conversation has ended.', 'chatchi' ),
			'newChat'        => __( 'Start a new conversation', 'chatchi' ),
			'typing'         => __( 'Typing…', 'chatchi' ),
			/* translators: %s: name of the support agent. */
			'typingNamed'    => __( '%s is typing…', 'chatchi' ),
			'emoji'          => __( 'Emoji', 'chatchi' ),
			'sendHint'       => __( 'Enter to send, Shift+Enter for a new line', 'chatchi' ),
			'newMessage'     => __( 'New message', 'chatchi' ),
			'jumpLatest'     => __( 'Go to the latest message', 'chatchi' ),
			'today'          => __( 'Today', 'chatchi' ),
			'yesterday'      => __( 'Yesterday', 'chatchi' ),
			/* translators: %d: number of unread messages. */
			'unread'         => __( '%d unread messages', 'chatchi' ),
			/* translators: %d: number of characters left. */
			'charactersLeft' => __( '%d characters left', 'chatchi' ),
			/* translators: %d: number of seconds. */
			'waitSeconds'    => __( 'Too many messages. Try again in %d seconds.', 'chatchi' ),
			/* translators: %d: number of minutes. */
			'waitMinutes'    => __( 'Too many attempts. Try again in %d minutes.', 'chatchi' ),
		);
	}
}
