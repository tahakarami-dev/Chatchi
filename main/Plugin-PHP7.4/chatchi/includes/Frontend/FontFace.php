<?php
/**
 * Bundled font.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The @font-face rule for the bundled Vazirmatn variable font.
 */
final class FontFace {

	public const FAMILY = 'ChatChi Vazirmatn';

	/**
	 * Returns the CSS rule.
	 *
	 * A plugin-specific family name avoids clashing with a theme that ships
	 * its own Vazirmatn. Browsers only download it once text uses it.
	 */
	public function css(): string {
		$url    = esc_url( CHATCHI_URL . 'assets/fonts/vazirmatn/Vazirmatn-Variable.woff2' );
		$family = self::FAMILY;

		return "@font-face{font-family:'{$family}';src:url('{$url}') format('woff2-variations'),url('{$url}') format('woff2');font-weight:100 900;font-style:normal;font-display:swap;}";
	}
}
