<?php
/**
 * Visitor persistence.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;
use ChatChi\Models\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the visitors table.
 *
 * Values are stored as given (truncated to column size); validation and
 * sanitization belong to the calling service.
 *
 * @method Visitor|null          find( int $id )
 * @method array<int, Visitor>   find_many( int[] $ids )
 */
final class VisitorRepository extends Repository {

	private const PROFILE_LENGTHS = array(
		'name'  => 100,
		'email' => 100,
		'phone' => 20,
	);

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return Table::VISITORS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array(
			'token_hash'   => '%s',
			'user_id'      => '%d',
			'name'         => '%s',
			'email'        => '%s',
			'phone'        => '%s',
			'created_at'   => '%s',
			'last_seen_at' => '%s',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hydrate( array $row ): Visitor {
		return Visitor::from_row( $row );
	}

	/**
	 * Creates a visitor.
	 *
	 * @param string                $token_hash SHA-256 hex hash of the visitor token.
	 * @param array<string, string> $profile    Optional name, email and phone.
	 * @param int                   $user_id    Linked WordPress user, 0 for guests.
	 *
	 * @throws \InvalidArgumentException When the hash is malformed.
	 * @throws DatabaseException         When the insert fails.
	 */
	public function create( string $token_hash, array $profile = array(), int $user_id = 0 ): Visitor {
		$this->assert_valid_hash( $token_hash );

		$now = $this->now();
		$id  = $this->insert_row(
			array_merge(
				array(
					'name'  => '',
					'email' => '',
					'phone' => '',
				),
				$this->profile_fields( $profile ),
				array(
					'token_hash'   => $token_hash,
					'user_id'      => max( 0, $user_id ),
					'created_at'   => $now,
					'last_seen_at' => $now,
				)
			)
		);

		return $this->find_or_fail( $id );
	}

	/**
	 * Finds the visitor that owns a token.
	 *
	 * @param string $token_hash SHA-256 hex hash of the visitor token.
	 */
	public function find_by_token_hash( string $token_hash ): ?Visitor {
		if ( ! $this->is_valid_hash( $token_hash ) ) {
			return null;
		}

		$rows = $this->select(
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE token_hash = %s LIMIT 1", $token_hash )
		);

		return $rows ? $rows[0] : null;
	}

	/**
	 * Finds the visitor linked to a WordPress user (the newest, if several).
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function find_by_user_id( int $user_id ): ?Visitor {
		if ( $user_id <= 0 ) {
			return null;
		}

		$rows = $this->select(
			$this->db->prepare( "SELECT * FROM {$this->table()} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id )
		);

		return $rows ? $rows[0] : null;
	}

	/**
	 * Updates any of name, email and phone.
	 *
	 * @param int                   $id      Visitor ID.
	 * @param array<string, string> $profile Fields to change.
	 * @return bool Whether anything changed.
	 */
	public function update_profile( int $id, array $profile ): bool {
		return $this->update_row( $id, $this->profile_fields( $profile ) );
	}

	/**
	 * Links the visitor to a WordPress user.
	 *
	 * @param int $id      Visitor ID.
	 * @param int $user_id WordPress user ID.
	 */
	public function link_user( int $id, int $user_id ): bool {
		return $this->update_row( $id, array( 'user_id' => max( 0, $user_id ) ) );
	}

	/**
	 * Records that the visitor was just active.
	 *
	 * @param int $id Visitor ID.
	 */
	public function touch( int $id ): bool {
		return $this->update_row( $id, array( 'last_seen_at' => $this->now() ) );
	}

	/**
	 * Keeps known profile fields and fits them to their columns.
	 *
	 * @param array<string, mixed> $profile Raw profile.
	 * @return array<string, string>
	 */
	private function profile_fields( array $profile ): array {
		$fields = array();

		foreach ( self::PROFILE_LENGTHS as $field => $length ) {
			if ( isset( $profile[ $field ] ) && is_scalar( $profile[ $field ] ) ) {
				$fields[ $field ] = $this->fit( (string) $profile[ $field ], $length );
			}
		}

		return $fields;
	}

	private function is_valid_hash( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
	}

	/**
	 * @param string $hash Token hash.
	 *
	 * @throws \InvalidArgumentException When the hash is malformed.
	 */
	private function assert_valid_hash( string $hash ): void {
		if ( ! $this->is_valid_hash( $hash ) ) {
			throw new \InvalidArgumentException( 'Visitor token hash must be a 64 character lowercase hex string.' );
		}
	}

	/**
	 * @param int $id Visitor ID.
	 *
	 * @throws DatabaseException When the row cannot be read back.
	 */
	private function find_or_fail( int $id ): Visitor {
		$visitor = $this->find( $id );

		if ( ! $visitor instanceof Visitor ) {
			throw new DatabaseException( sprintf( 'ChatChi visitor %d could not be read after insert.', $id ) );
		}

		return $visitor;
	}
}
