<?php
/**
 * Visitor cookie.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the cookie that carries a guest visitor's secret token.
 *
 * The cookie is HttpOnly (JavaScript, and therefore XSS, cannot read it),
 * SameSite=Lax (not sent on cross-site POSTs) and Secure on HTTPS sites.
 */
final class VisitorCookie {

	public const NAME = 'chatchi_visitor';

	/**
	 * A readable companion cookie holding only "1": it tells the widget a
	 * visitor identity exists, so pages of visitors who never chatted skip
	 * the session request until the chat is opened. It carries no secret.
	 */
	public const MARKER = 'chatchi_active';

	private const DEFAULT_LIFETIME = 30 * DAY_IN_SECONDS;

	private const MIN_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Browsers cap cookie lifetime at 400 days.
	 */
	private const MAX_LIFETIME = 400 * DAY_IN_SECONDS;

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings; without them the built-in defaults apply.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

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
		$default  = $this->settings ? (int) $this->settings->get( 'visitor_cookie_days' ) * DAY_IN_SECONDS : self::DEFAULT_LIFETIME;
		$lifetime = (int) apply_filters( 'chatchi_visitor_cookie_lifetime', $default );

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

		$options = array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		$sent = setcookie( self::NAME, $value, $options );

		// The marker follows the identity cookie: set with it, expired with it.
		$options['httponly'] = false;
		setcookie( self::MARKER, '' !== $value ? '1' : '', $options );

		return $sent;
	}
}
