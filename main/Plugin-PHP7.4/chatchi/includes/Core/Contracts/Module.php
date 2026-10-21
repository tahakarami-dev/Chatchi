<?php
/**
 * Contract for bootable feature modules.
 *
 * @package ChatChi
 */

namespace ChatChi\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A module groups the WordPress hooks of one feature.
 *
 * Constructors must stay side-effect free; hooks are added in register().
 */
interface Module {

	/**
	 * Adds the module's actions, filters, shortcodes and routes.
	 */
	public function register(): void;
}
