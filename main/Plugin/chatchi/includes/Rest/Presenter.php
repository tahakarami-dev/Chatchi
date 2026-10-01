<?php
/**
 * REST response shapes.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationSummary;
use ChatChi\Messages\MessagePage;
use ChatChi\Models\Agent;
use ChatChi\Models\Attachment;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Models\Visitor;
use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns models into the arrays the API returns.
 *
 * Each audience gets only what it needs: visitors never see agents' user
 * IDs or other visitors' data, and nobody ever sees token hashes. Dates are
 * ISO 8601 in UTC. Message bodies are plain text and must be rendered as
 * text by clients (textContent), never as HTML.
 */
final class Presenter {

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings, for the AI bot's name.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Formats a stored UTC datetime as ISO 8601.
	 *
	 * @param string|null $datetime MySQL datetime in UTC.
	 */
	public function date( ?string $datetime ): ?string {
		if ( null === $datetime || '' === $datetime ) {
			return null;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Public agent card, safe to show to visitors.
	 *
	 * @param Agent|null $agent Agent.
	 * @return array<string, mixed>|null
	 */
	public function agent( ?Agent $agent ): ?array {
		if ( ! $agent ) {
			return null;
		}

		return array(
			'id'         => $agent->id,
			'name'       => $agent->display_name,
			'avatar_url' => $this->avatar_url( $agent ),
		);
	}

	/**
	 * The signed-in agent's own profile.
	 *
	 * @param Agent $agent Agent.
	 * @return array<string, mixed>
	 */
	public function agent_self( Agent $agent ): array {
		return $this->agent( $agent ) + array(
			'user_id'   => $agent->user_id,
			'avatar_id' => $agent->avatar_id,
			'is_active' => $agent->is_active,
			'status'    => $agent->status,
		);
	}

	/**
	 * An agent with its WordPress account, for the management screen.
	 *
	 * @param Agent         $agent      Agent.
	 * @param \WP_User|null $user       Its WordPress user.
	 * @param int           $viewer_id  Current user ID, to flag the viewer's own record.
	 * @return array<string, mixed>
	 */
	public function agent_for_admin( Agent $agent, ?\WP_User $user, int $viewer_id ): array {
		return $this->agent( $agent ) + array(
			'user_id'    => $agent->user_id,
			'avatar_id'  => $agent->avatar_id,
			'is_active'  => $agent->is_active,
			'status'     => $agent->status,
			'online'     => $agent->is_available(),
			'is_me'      => $agent->user_id === $viewer_id,
			'user'       => $user ? array(
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			) : null,
			'created_at' => $this->date( $agent->created_at ),
		);
	}

	/**
	 * A WordPress user who can be made an agent.
	 *
	 * @param \WP_User $user User.
	 * @return array<string, mixed>
	 */
	public function user_candidate( \WP_User $user ): array {
		return array(
			'id'           => $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
		);
	}

	/**
	 * The visitor's own details, for the widget.
	 *
	 * @param Visitor $visitor Visitor.
	 * @return array<string, string>
	 */
	public function visitor_self( Visitor $visitor ): array {
		return array(
			'name'  => $visitor->name,
			'email' => $visitor->email,
			'phone' => $visitor->phone,
		);
	}

	/**
	 * Visitor details for agents.
	 *
	 * @param Visitor|null $visitor Visitor.
	 * @return array<string, mixed>|null
	 */
	public function visitor_for_agent( ?Visitor $visitor ): ?array {
		if ( ! $visitor ) {
			return null;
		}

		return array(
			'id'           => $visitor->id,
			'name'         => $visitor->name,
			'email'        => $visitor->email,
			'phone'        => $visitor->phone,
			'user_id'      => $visitor->user_id,
			'created_at'   => $this->date( $visitor->created_at ),
			'last_seen_at' => $this->date( $visitor->last_seen_at ),
		);
	}

	/**
	 * A conversation as its visitor sees it.
	 *
	 * @param Conversation $conversation Conversation.
	 * @param Agent|null   $agent        Assigned agent.
	 * @return array<string, mixed>
	 */
	public function conversation_for_visitor( Conversation $conversation, ?Agent $agent ): array {
		return array(
			'id'              => $conversation->id,
			'status'          => $conversation->status,
			'agent'           => $this->agent( $agent ),
			'created_at'      => $this->date( $conversation->created_at ),
			'last_message_at' => $this->date( $conversation->last_message_at ),
			'closed_at'       => $this->date( $conversation->closed_at ),
			'rating'          => $conversation->rating,
		);
	}

	/**
	 * A conversation with inbox details, for agents.
	 *
	 * @param ConversationSummary $summary Conversation summary.
	 * @param Actor               $viewer  Viewing agent.
	 * @return array<string, mixed>
	 */
	public function conversation_for_agent( ConversationSummary $summary, Actor $viewer ): array {
		$conversation = $summary->conversation;
		$agents       = $summary->agent ? array( $summary->agent->id => $summary->agent ) : array();

		return array(
			'id'              => $conversation->id,
			'status'          => $conversation->status,
			'visitor'         => $this->visitor_for_agent( $summary->visitor ),
			'agent'           => $this->agent( $summary->agent ),
			'page_url'        => $conversation->page_url,
			'unread_count'    => $summary->unread_count,
			'tags'            => $summary->tags,
			'last_message'    => $summary->last_message ? $this->message( $summary->last_message, $viewer, $agents ) : null,
			'created_at'      => $this->date( $conversation->created_at ),
			'last_message_at' => $this->date( $conversation->last_message_at ),
			'closed_at'       => $this->date( $conversation->closed_at ),
			'rating'          => null === $conversation->rating ? null : array(
				'score'   => $conversation->rating,
				'comment' => $conversation->rating_comment,
				'at'      => $this->date( $conversation->rated_at ),
			),
		);
	}

	/**
	 * A message, from the viewer's perspective.
	 *
	 * @param Message           $message Message.
	 * @param Actor             $viewer  Who is reading.
	 * @param array<int, Agent> $agents  Agents by ID, to name agent senders.
	 * @param array<int, Attachment> $attachments Attachments by ID, to describe files.
	 * @return array<string, mixed>
	 */
	public function message( Message $message, Actor $viewer, array $agents = array(), array $attachments = array() ): array {
		$sender = array(
			'type'       => $message->sender_type,
			'name'       => null,
			'avatar_url' => null,
		);

		if ( Message::SENDER_AGENT === $message->sender_type && isset( $agents[ $message->sender_id ] ) ) {
			$sender['name']       = $agents[ $message->sender_id ]->display_name;
			$sender['avatar_url'] = $this->avatar_url( $agents[ $message->sender_id ] );
		}

		// Agent messages with sender ID 0 are the AI bot's answers.
		$sender['bot'] = Message::SENDER_AGENT === $message->sender_type && 0 === $message->sender_id;

		if ( $sender['bot'] ) {
			$sender['name'] = $this->settings ? $this->settings->bot_name() : __( 'Smart assistant', 'chatchi' );
		}

		return array(
			'id'              => $message->id,
			'conversation_id' => $message->conversation_id,
			'sender'          => $sender,
			'is_mine'         => $message->sender_type === $viewer->type() && $message->sender_id === $viewer->id(),
			'body'            => $message->body,
			'internal'        => $message->internal,
			'client_id'       => $message->client_id,
			'attachment'      => isset( $attachments[ $message->attachment_id ] ) ? $this->attachment( $attachments[ $message->attachment_id ], $viewer ) : null,
			'has_attachment'  => $message->attachment_id > 0,
			'created_at'      => $this->date( $message->created_at ),
			'read_at'         => $this->date( $message->read_at ),
		);
	}

	/**
	 * A page of messages.
	 *
	 * @param MessagePage $page   Messages.
	 * @param Actor       $viewer Who is reading.
	 * @param array<int, Attachment> $attachments Attachments of the messages, by ID.
	 * @return array<string, mixed>
	 */
	public function message_page( MessagePage $page, Actor $viewer, array $attachments = array() ): array {
		$items = array();

		foreach ( $page->messages as $message ) {
			$items[] = $this->message( $message, $viewer, $page->agents, $attachments );
		}

		return array(
			'items'    => $items,
			'has_more' => $page->has_more,
			'last_id'  => $page->last_id(),
			'typing'   => $page->typing,
		);
	}

	/**
	 * A file, with the address the viewer downloads it from.
	 *
	 * @param Attachment $attachment Attachment.
	 * @param Actor      $viewer     Who is reading; decides the API base.
	 * @return array<string, mixed>
	 */
	public function attachment( Attachment $attachment, Actor $viewer ): array {
		$base = $viewer->is_agent() ? 'agent' : 'widget';

		return array(
			'id'       => $attachment->id,
			'name'     => $attachment->file_name,
			'mime'     => $attachment->mime,
			'size'     => $attachment->size,
			'is_image' => $attachment->is_image(),
			'is_audio' => $attachment->is_audio(),
			'duration' => $attachment->duration,
			'width'    => $attachment->width,
			'height'   => $attachment->height,
			'url'      => rest_url( sprintf( 'chatchi/v1/%s/conversations/%d/attachments/%d', $base, $attachment->conversation_id, $attachment->id ) ),
		);
	}

	/**
	 * Uploaded avatar URL, or null so clients show initials.
	 *
	 * Gravatar is deliberately not used: its URL is derived from the agent's
	 * email address, which visitors should not be able to infer.
	 *
	 * @param Agent $agent Agent.
	 */
	private function avatar_url( Agent $agent ): ?string {
		if ( $agent->avatar_id <= 0 ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $agent->avatar_id, 'thumbnail' );

		return is_string( $url ) ? $url : null;
	}
}
