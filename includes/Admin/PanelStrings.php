<?php
/**
 * Agent panel interface text.
 *
 * @package ChatChi
 */

namespace ChatChi\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every string the agent panel shows, translated on the server.
 */
final class PanelStrings {

	/**
	 * @return array<string, string>
	 */
	public function all(): array {
		return array(
			'appName'            => __( 'ChatChi', 'chatchi' ),
			'conversations'      => __( 'Conversations', 'chatchi' ),
			'backToWp'           => __( 'Back to WordPress', 'chatchi' ),
			'search'             => __( 'Search name, email, phone or number', 'chatchi' ),
			'all'                => __( 'All', 'chatchi' ),
			'statusNew'          => __( 'New', 'chatchi' ),
			'statusActive'       => __( 'Active', 'chatchi' ),
			'statusClosed'       => __( 'Closed', 'chatchi' ),
			'loading'            => __( 'Loading…', 'chatchi' ),
			'loadError'          => __( 'Could not load conversations.', 'chatchi' ),
			'retry'              => __( 'Try again', 'chatchi' ),
			'emptyInbox'         => __( 'No conversations yet', 'chatchi' ),
			'emptyInboxHint'     => __( 'When a visitor starts a chat on your site, it shows up here.', 'chatchi' ),
			'emptyFilter'        => __( 'Nothing here', 'chatchi' ),
			/* translators: %s: search text. */
			'noResults'          => __( 'No results for “%s”', 'chatchi' ),
			'loadMore'           => __( 'Load more', 'chatchi' ),
			'selectConversation' => __( 'Select a conversation', 'chatchi' ),
			'selectHint'         => __( 'Pick a conversation from the list to read and reply.', 'chatchi' ),
			'visitor'            => __( 'Visitor', 'chatchi' ),
			/* translators: %d: visitor number. */
			'visitorNumber'      => __( 'Visitor #%d', 'chatchi' ),
			'visitorInfo'        => __( 'Visitor details', 'chatchi' ),
			'name'               => __( 'Name', 'chatchi' ),
			'email'              => __( 'Email', 'chatchi' ),
			'phone'              => __( 'Mobile number', 'chatchi' ),
			'startedOn'          => __( 'Started', 'chatchi' ),
			'lastSeen'           => __( 'Last seen', 'chatchi' ),
			'startPage'          => __( 'Started from', 'chatchi' ),
			'wpUser'             => __( 'WordPress user', 'chatchi' ),
			'viewProfile'        => __( 'View profile', 'chatchi' ),
			'assignedTo'         => __( 'Assigned to', 'chatchi' ),
			'unassigned'         => __( 'Nobody yet', 'chatchi' ),
			'status'             => __( 'Status', 'chatchi' ),
			'notProvided'        => __( 'Not provided', 'chatchi' ),
			'closeConversation'  => __( 'Close conversation', 'chatchi' ),
			'closeConfirm'       => __( 'Close this conversation? The visitor will see that it has ended.', 'chatchi' ),
			'closeYes'           => __( 'Close', 'chatchi' ),
			'cancel'             => __( 'Cancel', 'chatchi' ),
			'closedNotice'       => __( 'This conversation is closed.', 'chatchi' ),
			'placeholder'        => __( 'Write a reply…', 'chatchi' ),
			'send'               => __( 'Send', 'chatchi' ),
			'sending'            => __( 'Sending…', 'chatchi' ),
			'sent'               => __( 'Sent', 'chatchi' ),
			'read'               => __( 'Read', 'chatchi' ),
			'sendFailed'         => __( 'Not sent. Click to retry.', 'chatchi' ),
			'earlier'            => __( 'Load earlier messages', 'chatchi' ),
			'noMessages'         => __( 'No messages yet.', 'chatchi' ),
			'today'              => __( 'Today', 'chatchi' ),
			'yesterday'          => __( 'Yesterday', 'chatchi' ),
			'you'                => __( 'You', 'chatchi' ),
			'back'               => __( 'Back', 'chatchi' ),
			'details'            => __( 'Details', 'chatchi' ),
			'networkError'       => __( 'Connection problem. Check your internet and try again.', 'chatchi' ),
			/* translators: %d: number of unread messages. */
			'unread'             => __( '%d unread messages', 'chatchi' ),
		);
	}
}
