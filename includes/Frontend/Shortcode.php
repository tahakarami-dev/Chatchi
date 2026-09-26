<?php
/**
 * [chatchi] shortcode.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the floating chat widget on any page that contains the shortcode,
 * even where the display rules would hide it.
 *
 * The shortcode prints nothing itself; the widget is the same floating
 * widget as everywhere else. [hamyarchat] is an alias.
 */
final class Shortcode implements Module {

	public const TAGS = array( 'chatchi', 'hamyarchat' );

	private WidgetModule $widget;

	/**
	 * @param WidgetModule $widget Widget loader.
	 */
	public function __construct( WidgetModule $widget ) {
		$this->widget = $widget;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		foreach ( self::TAGS as $tag ) {
			add_shortcode( $tag, array( $this, 'render' ) );
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * Covers shortcodes the widget loader cannot see in advance, such as in
	 * widget areas or page builder layouts: the widget is then loaded late,
	 * in the page footer.
	 */
	public function render(): string {
		$this->widget->force();

		return '';
	}

	/**
	 * Whether a text contains one of the shortcodes.
	 *
	 * @param string $content Post content.
	 */
	public static function in( string $content ): bool {
		if ( false === strpos( $content, '[' ) ) {
			return false;
		}

		foreach ( self::TAGS as $tag ) {
			if ( has_shortcode( $content, $tag ) ) {
				return true;
			}
		}

		return false;
	}
}
