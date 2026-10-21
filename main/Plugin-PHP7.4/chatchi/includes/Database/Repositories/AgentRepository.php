<?php
/**
 * Agent persistence.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;
use ChatChi\Models\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the agents table.
 *
 * @method Agent|null        find( int $id )
 * @method array<int, Agent> find_many( int[] $ids )
 */
final class AgentRepository extends Repository {

	private const DISPLAY_NAME_LENGTH = 100;

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return Table::AGENTS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array(
			'user_id'      => '%d',
			'display_name' => '%s',
			'avatar_id'    => '%d',
			'is_active'    => '%d',
			'status'       => '%s',
			'last_seen_at' => '%s',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hydrate( array $row ): Agent {
		return Agent::from_row( $row );
	}

	/**
	 * Creates an agent for a WordPress user.
	 *
	 * @param int    $user_id      WordPress user ID; one agent per user.
	 * @param string $display_name Name shown to visitors.
	 * @param int    $avatar_id    Attachment ID, 0 for none.
	 * @param bool   $is_active    Whether the agent can answer conversations.
	 *
	 * @throws \InvalidArgumentException When the user ID is invalid.
	 * @throws DatabaseException         When the insert fails, e.g. the user is already an agent.
	 */
	public function create( int $user_id, string $display_name, int $avatar_id = 0, bool $is_active = true ): Agent {
		if ( $user_id <= 0 ) {
			throw new \InvalidArgumentException( 'Agent user ID must be a positive integer.' );
		}

		$now = $this->now();
		$id  = $this->insert_row(
			array(
				'user_id'      => $user_id,
				'display_name' => $this->fit( $display_name, self::DISPLAY_NAME_LENGTH ),
				'avatar_id'    => max( 0, $avatar_id ),
				'is_active'    => $is_active ? 1 : 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		$agent = $this->find( $id );

		if ( ! $agent instanceof Agent ) {
			throw new DatabaseException( sprintf( 'ChatChi agent %d could not be read after insert.', $id ) );
		}

		return $agent;
	}

	/**
	 * Finds the agent record of a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function find_by_user_id( int $user_id ): ?Agent {
		if ( $user_id <= 0 ) {
			return null;
		}

		$rows = $this->select(
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d LIMIT 1", $user_id )
		);

		return $rows ? $rows[0] : null;
	}

	/**
	 * Lists agents alphabetically.
	 *
	 * @param bool $active_only Only return active agents.
	 * @return Agent[]
	 */
	public function all( bool $active_only = false ): array {
		if ( $active_only ) {
			return $this->select(
				$this->db->prepare( "SELECT * FROM {$this->table()} WHERE is_active = %d ORDER BY display_name ASC, id ASC", 1 )
			);
		}

		return $this->select( "SELECT * FROM {$this->table()} ORDER BY display_name ASC, id ASC" );
	}

	/**
	 * Updates display name, avatar and/or active state.
	 *
	 * @param int                  $id   Agent ID.
	 * @param array<string, mixed> $data Any of display_name, avatar_id, is_active.
	 * @return bool Whether anything changed.
	 */
	public function update( int $id, array $data ): bool {
		$fields = array();

		if ( array_key_exists( 'display_name', $data ) ) {
			$fields['display_name'] = $this->fit( (string) $data['display_name'], self::DISPLAY_NAME_LENGTH );
		}

		if ( array_key_exists( 'avatar_id', $data ) ) {
			$fields['avatar_id'] = max( 0, (int) $data['avatar_id'] );
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$fields['is_active'] = $data['is_active'] ? 1 : 0;
		}

		if ( ! $fields ) {
			return false;
		}

		$fields['updated_at'] = $this->now();

		return $this->update_row( $id, $fields );
	}

	/**
	 * Records that the agent's panel is open. Writes at most every 30
	 * seconds per agent, so frequent polling stays cheap.
	 *
	 * @param int $id Agent ID.
	 * @return bool Whether the row changed.
	 */
	public function touch( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$now = $this->now();

		return $this->execute(
			$this->db->prepare(
				"UPDATE {$this->table()} SET last_seen_at = %s WHERE id = %d AND ( last_seen_at IS NULL OR last_seen_at < %s )",
				$now,
				$id,
				gmdate( 'Y-m-d H:i:s', time() - 30 )
			)
		) > 0;
	}

	/**
	 * Sets an agent's availability.
	 *
	 * @param int    $id     Agent ID.
	 * @param string $status Agent::STATUS_ONLINE or Agent::STATUS_AWAY.
	 * @return bool Whether the row changed.
	 */
	public function set_status( int $id, string $status ): bool {
		if ( $id <= 0 || ! in_array( $status, array( Agent::STATUS_ONLINE, Agent::STATUS_AWAY ), true ) ) {
			return false;
		}

		return $this->update_row( $id, array( 'status' => $status ) );
	}

	/**
	 * Counts agents who are available right now (see Agent::is_available()).
	 */
	public function count_available(): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table()} WHERE is_active = 1 AND status = %s AND last_seen_at >= %s",
				Agent::STATUS_ONLINE,
				gmdate( 'Y-m-d H:i:s', time() - Agent::PRESENCE_SECONDS )
			)
		);
	}

	/**
	 * Deletes an agent. Callers must unassign its conversations first.
	 *
	 * @param int $id Agent ID.
	 */
	public function delete( int $id ): bool {
		return $this->delete_row( $id );
	}
}
