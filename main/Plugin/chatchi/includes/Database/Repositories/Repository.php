<?php
/**
 * Base repository.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Shared, prepared-statement-only access to one custom table.
 *
 * Only columns declared in columns() can be written, so callers can never
 * inject arbitrary column names. Table names come from constants plus the
 * site prefix and are the only values interpolated into SQL.
 *
 * All datetimes are stored in UTC.
 */
abstract class Repository {

	protected \wpdb $db;

	/**
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the unprefixed table name.
	 */
	abstract protected function table_name(): string;

	/**
	 * Returns the writable columns and their wpdb formats.
	 *
	 * @return array<string, string>
	 */
	abstract protected function columns(): array;

	/**
	 * Converts a database row into a model.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return object
	 */
	abstract protected function hydrate( array $row );

	/**
	 * Finds a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function find( int $id ) {
		if ( $id <= 0 ) {
			return null;
		}

		$rows = $this->select( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );

		return $rows ? $rows[0] : null;
	}

	/**
	 * Finds several rows by primary key in one query.
	 *
	 * @param int[] $ids Row IDs.
	 * @return array<int, object> Models keyed by ID.
	 */
	public function find_many( array $ids ): array {
		$ids = $this->sanitize_ids( $ids );

		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$models       = $this->select( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id IN ({$placeholders})", $ids ) );

		$keyed = array();
		foreach ( $models as $model ) {
			$keyed[ $model->id ] = $model;
		}

		return $keyed;
	}

	/**
	 * Returns the prefixed table name.
	 */
	protected function table(): string {
		return $this->db->prefix . $this->table_name();
	}

	/**
	 * Inserts a row and returns its ID.
	 *
	 * @param array<string, mixed> $data Column values; unknown columns are dropped.
	 *
	 * @throws DatabaseException When the insert fails.
	 */
	protected function insert_row( array $data ): int {
		$data = $this->writable( $data );

		if ( false === $this->db->insert( $this->table(), $data, $this->formats( $data ) ) ) {
			throw DatabaseException::from_wpdb( $this->db, 'insert into ' . $this->table_name() );
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Updates a row by ID.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Column values; unknown columns are dropped.
	 * @return bool Whether a row changed.
	 *
	 * @throws DatabaseException When the update fails.
	 */
	protected function update_row( int $id, array $data ): bool {
		$data = $this->writable( $data );

		if ( ! $data || $id <= 0 ) {
			return false;
		}

		$result = $this->db->update( $this->table(), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) );

		if ( false === $result ) {
			throw DatabaseException::from_wpdb( $this->db, 'update of ' . $this->table_name() );
		}

		return $result > 0;
	}

	/**
	 * Deletes a row by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool Whether a row was deleted.
	 *
	 * @throws DatabaseException When the delete fails.
	 */
	protected function delete_row( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$result = $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		if ( false === $result ) {
			throw DatabaseException::from_wpdb( $this->db, 'delete from ' . $this->table_name() );
		}

		return $result > 0;
	}

	/**
	 * Runs a prepared SELECT and hydrates the rows.
	 *
	 * @param string $sql Prepared SQL.
	 * @return object[]
	 *
	 * @throws DatabaseException When the query fails.
	 */
	protected function select( string $sql ): array {
		$rows = $this->db->get_results( $sql, ARRAY_A );

		$this->assert_no_error( 'select from ' . $this->table_name() );

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Runs a prepared query that returns a single value.
	 *
	 * @param string $sql Prepared SQL.
	 * @return string|null
	 *
	 * @throws DatabaseException When the query fails.
	 */
	protected function scalar( string $sql ): ?string {
		$value = $this->db->get_var( $sql );

		$this->assert_no_error( 'select from ' . $this->table_name() );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Runs a prepared write query and returns the affected row count.
	 *
	 * @param string $sql Prepared SQL.
	 *
	 * @throws DatabaseException When the query fails.
	 */
	protected function execute( string $sql ): int {
		$result = $this->db->query( $sql );

		if ( false === $result ) {
			throw DatabaseException::from_wpdb( $this->db, 'write to ' . $this->table_name() );
		}

		return (int) $result;
	}

	/**
	 * Throws if the last query produced an error.
	 *
	 * @param string $operation Short description of the query.
	 *
	 * @throws DatabaseException When wpdb reports an error.
	 */
	protected function assert_no_error( string $operation ): void {
		if ( '' !== $this->db->last_error ) {
			throw DatabaseException::from_wpdb( $this->db, $operation );
		}
	}

	/**
	 * Returns the current UTC time in MySQL format.
	 */
	protected function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Returns positive, unique integer IDs.
	 *
	 * @param array<int|string> $ids Raw IDs.
	 * @return int[]
	 */
	protected function sanitize_ids( array $ids ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Truncates a string to a column's character length.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Column length.
	 */
	protected function fit( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}

	/**
	 * Keeps only declared columns.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array<string, mixed>
	 */
	private function writable( array $data ): array {
		return array_intersect_key( $data, $this->columns() );
	}

	/**
	 * Returns the wpdb formats matching the order of $data.
	 *
	 * @param array<string, mixed> $data Writable column values.
	 * @return string[]
	 */
	private function formats( array $data ): array {
		$columns = $this->columns();
		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $columns[ $column ];
		}

		return $formats;
	}
}
