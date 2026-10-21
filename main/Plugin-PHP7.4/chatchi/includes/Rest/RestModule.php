<?php
/**
 * REST API registration.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the chatchi/v1 routes and keeps their responses clean.
 *
 * Other plugins and themes sometimes print PHP notices or stray whitespace
 * while a request is handled, for example from hooks that run when a message
 * is sent. Printed before the JSON, that output makes the response unreadable
 * for the widget and sends the headers early, so cookies and Retry-After are
 * lost. For this plugin's own routes, anything printed while the request is
 * handled is held back and discarded before the JSON is sent.
 */
final class RestModule implements Module {

	/**
	 * @var RouteProvider[]
	 */
	private array $controllers;

	/**
	 * Output buffer level of the guard, or 0 when not guarding.
	 */
	private int $buffer_level = 0;

	/**
	 * @param RouteProvider ...$controllers Controllers to register.
	 */
	public function __construct( RouteProvider ...$controllers ) {
		$this->controllers = $controllers;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'guard_output' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'discard_stray_output' ), 0 );
	}

	/**
	 * Registers every controller's routes.
	 */
	public function register_routes(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Starts holding back output when the current request is for one of
	 * this plugin's routes.
	 */
	public function guard_output(): void {
		if ( $this->buffer_level > 0 || ! $this->is_own_request() ) {
			return;
		}

		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/**
	 * Discards whatever was printed while the request was handled, right
	 * before WordPress prints the JSON response.
	 *
	 * @param bool $served Whether the request was already served.
	 * @return bool Unchanged.
	 */
	public function discard_stray_output( $served ) {
		if ( $this->buffer_level <= 0 ) {
			return $served;
		}

		$stray = '';

		while ( ob_get_level() >= $this->buffer_level ) {
			$stray .= (string) ob_get_clean();
		}

		$this->buffer_level = 0;

		if ( '' !== trim( $stray ) ) {
			/**
			 * Fires when output printed by other code was removed from a
			 * ChatChi REST response.
			 *
			 * @param string $stray The removed output.
			 */
			do_action( 'chatchi_stray_output', $stray );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug aid: the output was removed from the response.
				error_log( 'ChatChi: removed output printed by other code from a REST response: ' . substr( wp_strip_all_tags( $stray ), 0, 500 ) );
			}
		}

		return $served;
	}

	/**
	 * Whether the current request is a REST request for chatchi/v1.
	 */
	private function is_own_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || empty( $GLOBALS['wp'] ) || ! isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return false;
		}

		$route = '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' );

		return 0 === strpos( $route, '/' . RouteProvider::NAMESPACE . '/' );
	}
}
