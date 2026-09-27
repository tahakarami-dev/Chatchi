<?php
/**
 * Agent model.
 *
 * @package ChatChi
 */

namespace ChatChi\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A WordPress user who answers conversations from the ChatChi panel.
 */
final class Agent {

	public const STATUS_ONLINE = 'online';
	public const STATUS_AWAY   = 'away';

	/**
	 * How long after the panel's last request an agent still counts as
	 * online, in seconds. The panel polls at least every 30 seconds, even
	 * in a background tab.
	 */
	public const PRESENCE_SECONDS = 90;

	public int $id = 0;

	public int $user_id = 0;

	public string $display_name = '';

	/**
	 * Media library attachment ID, 0 when no avatar is set.
	 */
	public int $avatar_id = 0;

	public bool $is_active = true;

	/**
	 * Chosen availability: online or away.
	 */
	public string $status = self::STATUS_ONLINE;

	/**
	 * When the agent's panel last talked to the server (UTC), or null.
	 */
	public ?string $last_seen_at = null;

	public string $created_at = '';

	public string $updated_at = '';

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$agent               = new self();
		$agent->id           = (int) $row['id'];
		$agent->user_id      = (int) $row['user_id'];
		$agent->display_name = (string) $row['display_name'];
		$agent->avatar_id    = (int) $row['avatar_id'];
		$agent->is_active    = (bool) (int) $row['is_active'];
		// Columns added in schema 5; absent while an upgrade is pending.
		$agent->status       = self::STATUS_AWAY === ( $row['status'] ?? '' ) ? self::STATUS_AWAY : self::STATUS_ONLINE;
		$agent->last_seen_at = empty( $row['last_seen_at'] ) ? null : (string) $row['last_seen_at'];
		$agent->created_at   = (string) $row['created_at'];
		$agent->updated_at   = (string) $row['updated_at'];

		return $agent;
	}

	/**
	 * Whether visitors can expect an answer from this agent right now:
	 * active, set to online, and with the panel open.
	 */
	public function is_available(): bool {
		if ( ! $this->is_active || self::STATUS_ONLINE !== $this->status || null === $this->last_seen_at ) {
			return false;
		}

		$seen = strtotime( $this->last_seen_at . ' UTC' );

		return false !== $seen && time() - $seen <= self::PRESENCE_SECONDS;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'display_name' => $this->display_name,
			'avatar_id'    => $this->avatar_id,
			'is_active'    => $this->is_active,
			'status'       => $this->status,
			'last_seen_at' => $this->last_seen_at,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		);
	}
}
