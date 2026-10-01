<?php
/**
 * Cross-site request protection for visitor endpoints.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects visitor requests that come from another site.
 *
 * Visitors are authenticated by a cookie, so their write requests need CSRF
 * protection. WordPress nonces do not fit here: guests share one nonce and
 * cached pages serve stale ones. Instead, two layers that work with caching:
 *
 * 1. The visitor cookie is SameSite=Lax, so browsers do not send it on
 *    cross-site fetches at all.
 * 2. This guard refuses requests whose Origin is another host, or that the
 *    browser labels cross-site through Sec-Fetch-Site.
 */
final class RequestGuard {

	/**
	 * Whether the request comes from this site's own pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function is_same_site( \WP_REST_Request $request ): bool {
		$fetch_site = $request->get_header( 'sec_fetch_site' );

		if ( is_string( $fetch_site ) && 'cross-site' === strtolower( trim( $fetch_site ) ) ) {
			return false;
		}

		$origin = $request->get_header( 'origin' );

		if ( ! is_string( $origin ) || '' === $origin ) {
			// Browsers always send Origin on cross-origin fetches; its absence means same-origin or a non-browser client.
			return true;
		}

		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );

		if ( ! is_string( $origin_host ) ) {
			return false;
		}

		// The site's own address, with or without "www." (many sites answer
		// on both), and WordPress's own address when it differs.
		foreach ( array( home_url(), site_url() ) as $address ) {
			$site_host = wp_parse_url( $address, PHP_URL_HOST );

			if ( is_string( $site_host ) && self::bare_host( $origin_host ) === self::bare_host( $site_host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A host name in lower case, without a leading "www.".
	 *
	 * @param string $host Host name.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Returns the error for a rejected cross-site request.
	 */
	public function rejection(): \WP_Error {
		return new \WP_Error(
			'chatchi_cross_site_request',
			__( 'This request was rejected.', 'chatchi' ),
			array( 'status' => 403 )
		);
	}
}
