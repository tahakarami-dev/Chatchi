<?php
/**
 * Each agent's choice of light or dark panel.
 *
 * @package ChatChi
 */

namespace ChatChi\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stored per WordPress user, so the panel opens in the chosen theme on
 * every device without a flash of the other one.
 */
final class PanelTheme {

	public const META_KEY = 'chatchi_panel_theme';

	public const THEMES = array( 'light', 'dark', 'auto' );

	/**
	 * The user's theme: "light" (default), "dark" or "auto".
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function get( int $user_id ): string {
		$theme = $user_id > 0 ? get_user_meta( $user_id, self::META_KEY, true ) : '';

		return in_array( $theme, self::THEMES, true ) ? $theme : 'light';
	}

	/**
	 * @param int    $user_id WordPress user ID.
	 * @param string $theme   "light", "dark" or "auto".
	 */
	public static function set( int $user_id, string $theme ): void {
		if ( $user_id > 0 && in_array( $theme, self::THEMES, true ) ) {
			update_user_meta( $user_id, self::META_KEY, $theme );
		}
	}
}
