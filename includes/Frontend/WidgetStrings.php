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
			'statusOnlineLabel'=> __( 'Online', 'chatchi' ),
			'statusAway'     => __( 'We\'ll reply as soon as we can', 'chatchi' ),
			'statusOffline'  => __( 'Offline right now', 'chatchi' ),
			/* translators: %s: day and time support is back, e.g. "Saturday 09:00". */
			'backAt'         => __( 'Back %s', 'chatchi' ),
			'awayBanner'     => __( 'Nobody is online right now. Leave your message and we\'ll reply as soon as we\'re back.', 'chatchi' ),
			'offlineBanner'  => __( 'We\'re offline right now. We\'ll answer as soon as we\'re back.', 'chatchi' ),
			'yourMessage'    => __( 'Your message', 'chatchi' ),
			'messageRequired'=> __( 'Write your message.', 'chatchi' ),
			'sendMessage'    => __( 'Send message', 'chatchi' ),
			'offlineSent'    => __( 'Message received. We\'ll get back to you here or by email.', 'chatchi' ),
			'attach'         => __( 'Send a file', 'chatchi' ),
			'download'       => __( 'Download', 'chatchi' ),
			'uploading'      => __( 'Uploading…', 'chatchi' ),
			'dropFiles'      => __( 'Drop files here to send them', 'chatchi' ),
			/* translators: %s: maximum file size, e.g. "5 MB". */
			'fileTooLarge'   => __( 'Too large to send (the limit is %s). Tap to remove.', 'chatchi' ),
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
