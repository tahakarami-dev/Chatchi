<?php
/**
 * Visitor token helpers.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and hashes the secret tokens that identify guest visitors.
 *
 * Only the hash is stored. A plain SHA-256 is enough because the token has
 * 256 bits of entropy, so the hash cannot be brute-forced; unlike an HMAC
 * with the site salts, it also survives a salt rotation.
 */
final class VisitorToken {

	/**
	 * Returns a new random 64 character hex token.
	 *
	 * @throws \Exception When no secure random source is available.
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Returns the value stored in the database for a token.
	 *
	 * @param string $token Visitor token.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Returns the fixed hash that identifies a logged-in user's visitor row.
	 *
	 * Being deterministic, the unique token_hash index allows only one row per
	 * account, which makes concurrent first requests safe. The preimage is
	 * not a valid token, so no guest cookie can ever hash to it.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function hash_for_user( int $user_id ): string {
		return hash( 'sha256', 'chatchi-user:' . $user_id );
	}

	/**
	 * Checks the token format.
	 *
	 * @param string $token Candidate token.
	 */
	public static function is_valid( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}
}
