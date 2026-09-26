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
 * Registers the chatchi/v1 routes.
 */
final class RestModule implements Module {

	/**
	 * @var RouteProvider[]
	 */
	private array $controllers;

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every controller's routes.
	 */
	public function register_routes(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
