<?php
/**
 * Conversation list item.
 *
 * @package ChatChi
 */

namespace ChatChi\Conversations;

use ChatChi\Models\Agent;
use ChatChi\Models\Conversation;
use ChatChi\Models\Message;
use ChatChi\Models\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * A conversation with everything the agent inbox shows for it.
 */
final class ConversationSummary {

	public Conversation $conversation;

	public ?Visitor $visitor;

	public ?Agent $agent;

	public ?Message $last_message;

	/**
	 * Visitor messages the agents have not read yet.
	 */
	public int $unread_count;

	/**
	 * @param Conversation $conversation Conversation.
	 * @param Visitor|null $visitor      Its visitor.
	 * @param Agent|null   $agent        Assigned agent.
	 * @param Message|null $last_message Latest message.
	 * @param int          $unread_count Unread visitor messages.
	 */
	public function __construct( Conversation $conversation, ?Visitor $visitor, ?Agent $agent, ?Message $last_message, int $unread_count ) {
		$this->conversation = $conversation;
		$this->visitor      = $visitor;
		$this->agent        = $agent;
		$this->last_message = $last_message;
		$this->unread_count = $unread_count;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'conversation' => $this->conversation->to_array(),
			'visitor'      => $this->visitor ? $this->visitor->to_array() : null,
			'agent'        => $this->agent ? $this->agent->to_array() : null,
			'last_message' => $this->last_message ? $this->last_message->to_array() : null,
			'unread_count' => $this->unread_count,
		);
	}
}
