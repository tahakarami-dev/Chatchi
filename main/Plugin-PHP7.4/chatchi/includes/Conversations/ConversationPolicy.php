<?php
/**
 * Conversation access rules.
 *
 * @package ChatChi
 */

namespace ChatChi\Conversations;

use ChatChi\Models\Conversation;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may see and act on a conversation.
 *
 * - A visitor may only access their own conversations.
 * - An active agent may access every conversation; inactive agents none.
 */
final class ConversationPolicy {

	/**
	 * Whether the actor may read the conversation and its messages.
	 *
	 * @param Actor        $actor        Requesting actor.
	 * @param Conversation $conversation Target conversation.
	 */
	public function can_view( Actor $actor, Conversation $conversation ): bool {
		if ( $actor->is_visitor() ) {
			return $conversation->visitor_id === $actor->id();
		}

		return $this->is_active_agent( $actor );
	}

	/**
	 * Whether the actor may close the conversation.
	 *
	 * @param Actor        $actor        Requesting actor.
	 * @param Conversation $conversation Target conversation.
	 */
	public function can_close( Actor $actor, Conversation $conversation ): bool {
		return $this->can_view( $actor, $conversation );
	}

	/**
	 * Whether the actor may list and search all conversations.
	 *
	 * @param Actor $actor Requesting actor.
	 */
	public function can_list_all( Actor $actor ): bool {
		return $this->is_active_agent( $actor );
	}

	/**
	 * @param Actor $actor Requesting actor.
	 */
	private function is_active_agent( Actor $actor ): bool {
		$agent = $actor->agent_model();

		return $actor->is_agent() && null !== $agent && $agent->is_active;
	}
}
