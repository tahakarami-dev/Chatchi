<?php
/**
 * Rate-limit counters.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic hit counters, one row per bucket.
 *
 * A bucket is an opaque 64-character hash naming an action, a subject and a
 * time window. Rows carry their own expiry and are purged periodically.
 */
final class RateLimitRepository {

	private \wpdb $db;

	/**
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Adds one hit to a bucket, creating it if needed.
	 *
	 * The increment happens in a single statement, so concurrent requests
	 * can never lose a hit.
	 *
	 * @param string $bucket     Bucket hash.
	 * @param int    $expires_at Unix time after which the row may be purged.
	 *
	 * @throws DatabaseException When the write fails.
	 */
	public function hit( string $bucket, int $expires_at ): void {
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$this->table()} (bucket, hits, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bucket,
				$expires_at
			)
		);

		if ( false === $result ) {
			throw DatabaseException::from_wpdb( $this->db, 'hit on ' . Table::RATE_LIMITS );
		}
	}

	/**
	 * Returns the hit counts of several buckets.
	 *
	 * @param string[] $buckets Bucket hashes.
	 * @return array<string, int> Hits keyed by bucket; missing buckets are 0.
	 *
	 * @throws DatabaseException When the query fails.
	 */
	public function counts( array $buckets ): array {
		$counts = array_fill_keys( $buckets, 0 );

		if ( ! $buckets ) {
			return $counts;
		}

		$placeholders = implode( ',', array_fill( 0, count( $buckets ), '%s' ) );
		$rows         = $this->db->get_results(
			$this->db->prepare( "SELECT bucket, hits FROM {$this->table()} WHERE bucket IN ({$placeholders})", $buckets ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( $this->db->last_error ) {
			throw DatabaseException::from_wpdb( $this->db, 'select from ' . Table::RATE_LIMITS );
		}

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['bucket'] ] = (int) $row['hits'];
		}

		return $counts;
	}

	/**
	 * Deletes expired buckets.
	 *
	 * Rows live for at most two windows, so the table stays small and one
	 * unbounded delete is cheap.
	 *
	 * @param int $now Current Unix time.
	 * @return int Rows deleted.
	 *
	 * @throws DatabaseException When the delete fails.
	 */
	public function purge( int $now ): int {
		$result = $this->db->query(
			$this->db->prepare( "DELETE FROM {$this->table()} WHERE expires_at < %d", $now ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( false === $result ) {
			throw DatabaseException::from_wpdb( $this->db, 'purge of ' . Table::RATE_LIMITS );
		}

		return (int) $result;
	}

	private function table(): string {
		return $this->db->prefix . Table::RATE_LIMITS;
	}
}
