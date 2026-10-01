<?php
/**
 * Security housekeeping.
 *
 * @package ChatChi
 */

namespace ChatChi\Security;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the hourly clean-up of rate-limit counters and adds the
 * Retry-After header to rate-limited REST responses.
 */
final class SecurityModule implements Module {

	private RateLimiter $limiter;

	/**
	 * @param RateLimiter $limiter Rate limiter.
	 */
	public function __construct( RateLimiter $limiter ) {
		$this->limiter = $limiter;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( RateLimiter::PURGE_HOOK, array( $this->limiter, 'purge' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'retry_after' ), 10, 3 );
	}

	/**
	 * Makes sure the clean-up is scheduled.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( RateLimiter::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', RateLimiter::PURGE_HOOK );
		}
	}

	/**
	 * Adds Retry-After to 429 responses of this plugin.
	 *
	 * Works for errors returned by permission callbacks too, which cannot set
	 * headers themselves.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public function retry_after( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || 429 !== $response->get_status() ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_array( $data ) && 'chatchi_rate_limited' === ( $data['code'] ?? '' ) && isset( $data['data']['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) max( 1, (int) $data['data']['retry_after'] ) );
		}

		return $response;
	}
}
