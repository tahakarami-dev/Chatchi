<?php
/**
 * Contract for REST controllers.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * A controller that registers routes under chatchi/v1.
 */
interface RouteProvider {

	public const NAMESPACE = 'chatchi/v1';

	/**
	 * Registers the controller's routes. Called on rest_api_init.
	 */
	public function register_routes(): void;
}
