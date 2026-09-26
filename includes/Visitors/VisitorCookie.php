<?php
/**
 * Visitor cookie.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the cookie that carries a guest visitor's secret token.
 *
 * The cookie is HttpOnly (JavaScript, and therefore XSS, cannot read it),
 * SameSite=Lax (not sent on cross-site POSTs) and Secure on HTTPS sites.
 */
final class VisitorCookie {

	public const NAME = 'chatchi_visitor';

	private const DEFAULT_LIFETIME = 30 * DAY_IN_SECONDS;

	private const MIN_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Browsers cap cookie lifetime at 400 days.
	 */
	private const MAX_LIFETIME = 400 * DAY_IN_SECONDS;

	/**
	 * Returns the token from the request, or null when absent or malformed.
	 */
	public function read(): ?string {
		if ( ! isset( $_COOKIE[ self::NAME ] ) || ! is_string( $_COOKIE[ self::NAME ] ) ) {
			return null;
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) );

		return VisitorToken::is_valid( $token ) ? $token : null;
	}

	/**
	 * Sends the cookie and exposes it to the rest of the current request.
	 *
	 * @param string $token Visitor token.
	 * @return bool False when headers were already sent.
	 */
	public function write( string $token ): bool {
		$sent = $this->send( $token, time() + $this->lifetime() );

		if ( $sent ) {
			$_COOKIE[ self::NAME ] = $token;
		}

		return $sent;
	}

	/**
	 * Expires the cookie.
	 */
	public function clear(): void {
		$this->send( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::NAME ] );
	}

	/**
	 * Returns how long the visitor identity is remembered, in seconds.
	 */
	public function lifetime(): int {
		/**
		 * Filters how long a visitor's identity cookie lasts.
		 *
		 * @param int $lifetime Seconds. Clamped to one hour – 400 days.
		 */
		$lifetime = (int) apply_filters( 'chatchi_visitor_cookie_lifetime', self::DEFAULT_LIFETIME );

		return min( self::MAX_LIFETIME, max( self::MIN_LIFETIME, $lifetime ) );
	}

	/**
	 * @param string $value   Cookie value.
	 * @param int    $expires Unix timestamp.
	 */
	private function send( string $value, int $expires ): bool {
		if ( headers_sent() ) {
			return false;
		}

		return setcookie(
			self::NAME,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
