<?php
/**
 * Who is performing an action.
 *
 * @package ChatChi
 */

namespace ChatChi\Conversations;

use ChatChi\Models\Agent;
use ChatChi\Models\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor or agent on whose behalf a conversation action runs.
 *
 * Built by the caller (REST controllers in Phase 6) from the authenticated
 * request, then checked by ConversationPolicy.
 */
final class Actor {

	public const VISITOR = 'visitor';
	public const AGENT   = 'agent';

	private string $type;

	private ?Visitor $visitor;

	private ?Agent $agent;

	private function __construct( string $type, ?Visitor $visitor, ?Agent $agent ) {
		$this->type    = $type;
		$this->visitor = $visitor;
		$this->agent   = $agent;
	}

	/**
	 * @param Visitor $visitor Visitor making the request.
	 */
	public static function visitor( Visitor $visitor ): self {
		return new self( self::VISITOR, $visitor, null );
	}

	/**
	 * @param Agent $agent Agent making the request.
	 */
	public static function agent( Agent $agent ): self {
		return new self( self::AGENT, null, $agent );
	}

	public function is_visitor(): bool {
		return self::VISITOR === $this->type;
	}

	public function is_agent(): bool {
		return self::AGENT === $this->type;
	}

	/**
	 * Returns the visitor or agent ID.
	 */
	public function id(): int {
		return $this->is_visitor() ? $this->visitor->id : $this->agent->id;
	}

	public function type(): string {
		return $this->type;
	}

	public function visitor_model(): ?Visitor {
		return $this->visitor;
	}

	public function agent_model(): ?Agent {
		return $this->agent;
	}
}
