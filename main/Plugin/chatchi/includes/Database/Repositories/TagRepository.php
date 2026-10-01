<?php
/**
 * Conversation tag storage.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of {prefix}chatchi_conversation_tags: one per conversation and tag.
 */
final class TagRepository {

	private \wpdb $db;

	/**
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->prefix . Table::TAGS;
	}

	/**
	 * Tags of several conversations.
	 *
	 * @param int[] $conversation_ids Conversation IDs.
	 * @return array<int, string[]> Tags keyed by conversation ID, sorted.
	 *
	 * @throws DatabaseException When the query fails.
	 */
	public function for_conversations( array $conversation_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $conversation_ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders only.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT conversation_id, tag FROM {$this->table()} WHERE conversation_id IN ({$placeholders}) ORDER BY tag ASC", $ids ), ARRAY_A );
		$this->check( 'read' );

		$tags = array();
		foreach ( (array) $rows as $row ) {
			$tags[ (int) $row['conversation_id'] ][] = (string) $row['tag'];
		}

		return $tags;
	}

	/**
	 * Replaces a conversation's tags.
	 *
	 * @param int      $conversation_id Conversation ID.
	 * @param string[] $tags            Clean tags.
	 *
	 * @throws DatabaseException When a query fails.
	 */
	public function replace( int $conversation_id, array $tags ): void {
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->table()} WHERE conversation_id = %d", $conversation_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->check( 'delete' );

		foreach ( $tags as $tag ) {
			$this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$this->table()} (conversation_id, tag) VALUES (%d, %s)", $conversation_id, $tag ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->check( 'insert' );
		}
	}

	/**
	 * The most used tags, for suggestions and the inbox filter.
	 *
	 * @param int $limit Maximum tags.
	 * @return array<int, array{tag: string, count: int}>
	 *
	 * @throws DatabaseException When the query fails.
	 */
	public function popular( int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT tag, COUNT(*) AS total FROM {$this->table()} GROUP BY tag ORDER BY total DESC, tag ASC LIMIT %d", max( 1, $limit ) ), ARRAY_A );
		$this->check( 'read' );

		$tags = array();
		foreach ( (array) $rows as $row ) {
			$tags[] = array(
				'tag'   => (string) $row['tag'],
				'count' => (int) $row['total'],
			);
		}

		return $tags;
	}

	/**
	 * @param string $operation What was attempted, for the error.
	 *
	 * @throws DatabaseException When the last query failed.
	 */
	private function check( string $operation ): void {
		if ( '' !== $this->db->last_error ) {
			throw DatabaseException::from_wpdb( $this->db, $operation . ' of ' . Table::TAGS );
		}
	}
}
