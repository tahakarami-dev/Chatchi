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
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $origin_host ) && is_string( $site_host ) && strtolower( $origin_host ) === strtolower( $site_host );
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
