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
			'dismiss'        => __( 'Close', 'chatchi' ),
			'voiceRecord'    => __( 'Record a voice message', 'chatchi' ),
			'voiceRecording' => __( 'Recording…', 'chatchi' ),
			'voiceCancel'    => __( 'Delete recording', 'chatchi' ),
			'voiceSend'      => __( 'Send voice message', 'chatchi' ),
			'voiceMessage'   => __( 'Voice message', 'chatchi' ),
			'voicePlay'      => __( 'Play', 'chatchi' ),
			'voicePause'     => __( 'Pause', 'chatchi' ),
			'voiceLoadFailed'=> __( 'This voice message could not be played. Please try again.', 'chatchi' ),
			'micBlocked'     => __( 'Microphone access is blocked. Allow it in your browser\'s site settings to send voice messages.', 'chatchi' ),
			'micFailed'      => __( 'No microphone was found, or it is in use by another app.', 'chatchi' ),
			'ratingTitle'    => __( 'How was your chat?', 'chatchi' ),
			'ratingComment'  => __( 'Anything to add? (optional)', 'chatchi' ),
			'ratingSend'     => __( 'Send', 'chatchi' ),
			'ratingThanks'   => __( 'Thanks for your feedback!', 'chatchi' ),
			/* translators: %d: number of stars. */
			'ratingStars'    => __( '%d stars', 'chatchi' ),
			'rating1'        => __( 'Very bad', 'chatchi' ),
			'rating2'        => __( 'Bad', 'chatchi' ),
			'rating3'        => __( 'OK', 'chatchi' ),
			'rating4'        => __( 'Good', 'chatchi' ),
			'rating5'        => __( 'Excellent', 'chatchi' ),
			'today'          => __( 'Today', 'chatchi' ),
			'talkToPerson'   => __( 'Talk to a person', 'chatchi' ),
			'personComing'   => __( 'We\'ve told the team. A person will reply here soon.', 'chatchi' ),
			'faqTitle'       => __( 'Frequently asked questions', 'chatchi' ),
			'faqSearch'      => __( 'Search the questions', 'chatchi' ),
			'faqNone'        => __( 'No matching question. Ask us in the chat.', 'chatchi' ),
			/* translators: %d: number of questions. */
			'faqAll'         => __( 'Show all %d questions', 'chatchi' ),
			'faqHint'        => __( 'Didn\'t find your answer? Ask us below.', 'chatchi' ),
			'channelsTitle'  => __( 'Contact us', 'chatchi' ),
			'channelChat'    => __( 'Chat with us', 'chatchi' ),
			'channelWhatsapp' => __( 'WhatsApp', 'chatchi' ),
			'channelTelegram' => __( 'Telegram', 'chatchi' ),
			'channelBale'    => __( 'Bale', 'chatchi' ),
			'channelPhone'   => __( 'Call us', 'chatchi' ),
			'closeMenu'      => __( 'Close menu', 'chatchi' ),
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
