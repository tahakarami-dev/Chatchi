<?php
/**
 * A slice of a conversation's messages.
 *
 * @package ChatChi
 */

namespace ChatChi\Messages;

use ChatChi\Models\Agent;
use ChatChi\Models\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Messages in chronological order, plus the agents who wrote them.
 */
final class MessagePage {

	/**
	 * @var Message[]
	 */
	public array $messages;

	/**
	 * Agents who sent any of the messages, keyed by agent ID.
	 *
	 * @var array<int, Agent>
	 */
	public array $agents;

	/**
	 * Whether more messages exist in the requested direction.
	 */
	public bool $has_more;

	/**
	 * @param Message[]         $messages Messages, oldest first.
	 * @param array<int, Agent> $agents   Sending agents by ID.
	 * @param bool              $has_more More messages exist beyond this page.
	 */
	public function __construct( array $messages, array $agents, bool $has_more ) {
		$this->messages = $messages;
		$this->agents   = $agents;
		$this->has_more = $has_more;
	}

	/**
	 * Returns the ID of the newest message, or 0 when empty.
	 */
	public function last_id(): int {
		return $this->messages ? end( $this->messages )->id : 0;
	}
}
