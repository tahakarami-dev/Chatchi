<?php
/**
 * Conversation model.
 *
 * @package ChatChi
 */

namespace ChatChi\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A chat thread between one visitor and the site's agents.
 */
final class Conversation {

	public const STATUS_NEW    = 'new';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_CLOSED = 'closed';

	public int $id = 0;

	public int $visitor_id = 0;

	/**
	 * Assigned agent, 0 when unassigned.
	 */
	public int $agent_id = 0;

	public string $status = self::STATUS_NEW;

	/**
	 * Page the conversation was started from.
	 */
	public string $page_url = '';

	public int $last_message_id = 0;

	/**
	 * Time of the latest message, or the creation time before the first one.
	 */
	public string $last_message_at = '';

	public string $created_at = '';

	public string $updated_at = '';

	public ?string $closed_at = null;

	/**
	 * Returns every valid status.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::STATUS_NEW, self::STATUS_ACTIVE, self::STATUS_CLOSED );
	}

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$conversation                  = new self();
		$conversation->id              = (int) $row['id'];
		$conversation->visitor_id      = (int) $row['visitor_id'];
		$conversation->agent_id        = (int) $row['agent_id'];
		$conversation->status          = (string) $row['status'];
		$conversation->page_url        = (string) $row['page_url'];
		$conversation->last_message_id = (int) $row['last_message_id'];
		$conversation->last_message_at = (string) $row['last_message_at'];
		$conversation->created_at      = (string) $row['created_at'];
		$conversation->updated_at      = (string) $row['updated_at'];
		$conversation->closed_at       = null === $row['closed_at'] ? null : (string) $row['closed_at'];

		return $conversation;
	}

	public function is_closed(): bool {
		return self::STATUS_CLOSED === $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'visitor_id'      => $this->visitor_id,
			'agent_id'        => $this->agent_id,
			'status'          => $this->status,
			'page_url'        => $this->page_url,
			'last_message_id' => $this->last_message_id,
			'last_message_at' => $this->last_message_at,
			'created_at'      => $this->created_at,
			'updated_at'      => $this->updated_at,
			'closed_at'       => $this->closed_at,
		);
	}
}
