<?php
/**
 * Conversation persistence.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;
use ChatChi\Models\Conversation;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the conversations table.
 *
 * @method Conversation|null        find( int $id )
 * @method array<int, Conversation> find_many( int[] $ids )
 */
final class ConversationRepository extends Repository {

	private const PAGE_URL_LENGTH = 2048;

	private const MAX_PER_PAGE = 100;

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return Table::CONVERSATIONS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array(
			'visitor_id'      => '%d',
			'agent_id'        => '%d',
			'status'          => '%s',
			'page_url'        => '%s',
			'last_message_id' => '%d',
			'last_message_at' => '%s',
			'created_at'      => '%s',
			'updated_at'      => '%s',
			'closed_at'       => '%s',
			'open_visitor_id' => '%d',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hydrate( array $row ): Conversation {
		return Conversation::from_row( $row );
	}

	/**
	 * Opens a new conversation for a visitor.
	 *
	 * Fails with a DatabaseException when the visitor already has an open
	 * conversation (unique open_visitor key), which makes concurrent starts safe.
	 *
	 * @param int    $visitor_id Visitor ID.
	 * @param string $page_url   Page the chat was started from.
	 *
	 * @throws \InvalidArgumentException When the visitor ID is invalid.
	 * @throws DatabaseException         When the insert fails.
	 */
	public function create( int $visitor_id, string $page_url = '' ): Conversation {
		if ( $visitor_id <= 0 ) {
			throw new \InvalidArgumentException( 'Conversation visitor ID must be a positive integer.' );
		}

		$now = $this->now();
		$id  = $this->insert_row(
			array(
				'visitor_id'      => $visitor_id,
				'agent_id'        => 0,
				'status'          => Conversation::STATUS_NEW,
				'page_url'        => $this->fit( $page_url, self::PAGE_URL_LENGTH ),
				'last_message_id' => 0,
				'last_message_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
				'closed_at'       => null,
				'open_visitor_id' => $visitor_id,
			)
		);

		$conversation = $this->find( $id );

		if ( ! $conversation instanceof Conversation ) {
			throw new DatabaseException( sprintf( 'ChatChi conversation %d could not be read after insert.', $id ) );
		}

		return $conversation;
	}

	/**
	 * Returns the visitor's most recent conversation that is not closed.
	 *
	 * @param int $visitor_id Visitor ID.
	 */
	public function find_open_by_visitor( int $visitor_id ): ?Conversation {
		if ( $visitor_id <= 0 ) {
			return null;
		}

		$rows = $this->select(
			$this->db->prepare(
				"SELECT * FROM {$this->table()} WHERE visitor_id = %d AND status <> %s ORDER BY id DESC LIMIT 1",
				$visitor_id,
				Conversation::STATUS_CLOSED
			)
		);

		return $rows ? $rows[0] : null;
	}

	/**
	 * Changes the status. Closing stamps closed_at; reopening clears it.
	 *
	 * Keeps open_visitor_id in sync, so reopening fails with a
	 * DatabaseException when the visitor already has another open conversation.
	 *
	 * @param int    $id     Conversation ID.
	 * @param string $status One of Conversation::statuses().
	 * @return bool Whether the row changed.
	 *
	 * @throws \InvalidArgumentException When the status is unknown.
	 * @throws DatabaseException         When the update fails.
	 */
	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, Conversation::statuses(), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown conversation status "%s".', $status ) );
		}

		if ( $id <= 0 ) {
			return false;
		}

		$now = $this->now();

		if ( Conversation::STATUS_CLOSED === $status ) {
			$sql = $this->db->prepare(
				"UPDATE {$this->table()} SET status = %s, closed_at = %s, updated_at = %s, open_visitor_id = NULL WHERE id = %d",
				$status,
				$now,
				$now,
				$id
			);
		} else {
			$sql = $this->db->prepare(
				"UPDATE {$this->table()} SET status = %s, closed_at = NULL, updated_at = %s, open_visitor_id = visitor_id WHERE id = %d",
				$status,
				$now,
				$id
			);
		}

		return $this->execute( $sql ) > 0;
	}

	/**
	 * Moves a conversation from new to active, only if it is still new.
	 *
	 * @param int $id Conversation ID.
	 * @return bool Whether this call made the transition.
	 */
	public function activate_if_new( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		return $this->execute(
			$this->db->prepare(
				"UPDATE {$this->table()} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
				Conversation::STATUS_ACTIVE,
				$this->now(),
				$id,
				Conversation::STATUS_NEW
			)
		) > 0;
	}

	/**
	 * Assigns an agent only when nobody is assigned yet.
	 *
	 * @param int $id       Conversation ID.
	 * @param int $agent_id Agent ID.
	 * @return bool Whether this call assigned the agent.
	 */
	public function assign_if_unassigned( int $id, int $agent_id ): bool {
		if ( $id <= 0 || $agent_id <= 0 ) {
			return false;
		}

		return $this->execute(
			$this->db->prepare(
				"UPDATE {$this->table()} SET agent_id = %d, updated_at = %s WHERE id = %d AND agent_id = 0",
				$agent_id,
				$this->now(),
				$id
			)
		) > 0;
	}

	/**
	 * Assigns an agent (0 to unassign).
	 *
	 * @param int $id       Conversation ID.
	 * @param int $agent_id Agent ID.
	 */
	public function assign_agent( int $id, int $agent_id ): bool {
		return $this->update_row(
			$id,
			array(
				'agent_id'   => max( 0, $agent_id ),
				'updated_at' => $this->now(),
			)
		);
	}

	/**
	 * Unassigns an agent from all of its conversations.
	 *
	 * @param int $agent_id Agent ID.
	 * @return int Number of conversations changed.
	 */
	public function unassign_agent( int $agent_id ): int {
		if ( $agent_id <= 0 ) {
			return 0;
		}

		return $this->execute(
			$this->db->prepare(
				"UPDATE {$this->table()} SET agent_id = 0, updated_at = %s WHERE agent_id = %d",
				$this->now(),
				$agent_id
			)
		);
	}

	/**
	 * Points the conversation at its newest message.
	 *
	 * Only moves forward, so concurrent sends can never leave an older
	 * message recorded as the latest one.
	 *
	 * @param int    $id         Conversation ID.
	 * @param int    $message_id Message ID.
	 * @param string $created_at Message time (UTC, MySQL format).
	 * @return bool Whether the conversation changed.
	 */
	public function record_message( int $id, int $message_id, string $created_at ): bool {
		if ( $id <= 0 || $message_id <= 0 ) {
			return false;
		}

		return $this->execute(
			$this->db->prepare(
				"UPDATE {$this->table()} SET last_message_id = %d, last_message_at = %s, updated_at = %s WHERE id = %d AND last_message_id < %d",
				$message_id,
				$created_at,
				$this->now(),
				$id,
				$message_id
			)
		) > 0;
	}

	/**
	 * Lists conversations, newest activity first.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string $status   One of Conversation::statuses(), or '' for all.
	 *     @type string $search   Matches visitor name, email, phone, or an exact conversation ID.
	 *     @type int    $page     1-based page number.
	 *     @type int    $per_page Items per page, 1–100.
	 * }
	 * @return array{items: Conversation[], total: int, page: int, per_page: int}
	 */
	public function paginate( array $args = array() ): array {
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per_page = min( self::MAX_PER_PAGE, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );

		$where  = array( '1 = 1' );
		$params = array();

		if ( '' !== $status ) {
			if ( ! in_array( $status, Conversation::statuses(), true ) ) {
				return array(
					'items'    => array(),
					'total'    => 0,
					'page'     => $page,
					'per_page' => $per_page,
				);
			}

			$where[]  = 'c.status = %s';
			$params[] = $status;
		}

		$join = '';

		if ( '' !== $search ) {
			$visitors = $this->db->prefix . Table::VISITORS;
			$join     = "LEFT JOIN {$visitors} v ON v.id = c.visitor_id";
			$like     = '%' . $this->db->esc_like( $search ) . '%';
			$clauses  = array( 'v.name LIKE %s', 'v.email LIKE %s', 'v.phone LIKE %s' );
			$params   = array_merge( $params, array( $like, $like, $like ) );

			if ( ctype_digit( $search ) ) {
				$clauses[] = 'c.id = %d';
				$params[]  = (int) $search;
			}

			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		}

		$from = "FROM {$this->table()} c {$join} WHERE " . implode( ' AND ', $where );

		$total = (int) $this->scalar( $this->prepare_with( "SELECT COUNT(*) {$from}", $params ) );

		$items = array();
		if ( $total > 0 ) {
			$items = $this->select(
				$this->prepare_with(
					"SELECT c.* {$from} ORDER BY c.last_message_at DESC, c.id DESC LIMIT %d OFFSET %d",
					array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
				)
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Counts conversations per status.
	 *
	 * @return array<string, int> Every status, including those with no rows.
	 */
	public function count_by_status(): array {
		$counts = array_fill_keys( Conversation::statuses(), 0 );

		$rows = $this->db->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table()} GROUP BY status", ARRAY_A );
		$this->assert_no_error( 'count of ' . $this->table_name() );

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Prepares SQL only when it has placeholders; wpdb::prepare() rejects empty argument lists.
	 *
	 * @param string       $sql    SQL with placeholders.
	 * @param array<mixed> $params Values.
	 */
	private function prepare_with( string $sql, array $params ): string {
		return $params ? (string) $this->db->prepare( $sql, $params ) : $sql;
	}
}
