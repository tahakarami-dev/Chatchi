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

	public int $id = 0;

	public int $user_id = 0;

	public string $display_name = '';

	/**
	 * Media library attachment ID, 0 when no avatar is set.
	 */
	public int $avatar_id = 0;

	public bool $is_active = true;

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
		$agent->created_at   = (string) $row['created_at'];
		$agent->updated_at   = (string) $row['updated_at'];

		return $agent;
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
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		);
	}
}
