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
	 * Checks the token format.
	 *
	 * @param string $token Candidate token.
	 */
	public static function is_valid( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}
}
