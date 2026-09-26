<?php
/**
 * Front-end chat widget loader.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the widget script and its configuration on front-end pages.
 *
 * The page itself gets no markup: the script builds the widget inside a
 * Shadow DOM so theme styles cannot leak in or out. Nothing per-visitor is
 * printed into the page, so full-page caching keeps working; the only
 * per-user value is the REST nonce, and only for logged-in users, whose
 * pages are not cached.
 */
final class WidgetModule implements Module {

	public const SCRIPT_HANDLE = 'chatchi-widget';

	public const FONT_HANDLE = 'chatchi-fonts';

	private ColorPalette $palette;

	private WidgetStrings $strings;

	/**
	 * @param ColorPalette  $palette Color tokens.
	 * @param WidgetStrings $strings Interface text.
	 */
	public function __construct( ColorPalette $palette, WidgetStrings $strings ) {
		$this->palette = $palette;
		$this->strings = $strings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the widget should load on the current request.
	 */
	public function should_load(): bool {
		if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() || is_customize_preview() ) {
			return false;
		}

		/**
		 * Filters whether the chat widget loads on the current page.
		 *
		 * @param bool $enabled Whether to load the widget.
		 */
		return (bool) apply_filters( 'chatchi_widget_enabled', true );
	}

	/**
	 * Enqueues the font face, script and configuration.
	 */
	public function enqueue(): void {
		if ( ! $this->should_load() ) {
			return;
		}

		// @font-face must live in the document; Shadow DOM styles cannot declare fonts.
		wp_register_style( self::FONT_HANDLE, false, array(), CHATCHI_VERSION );
		wp_enqueue_style( self::FONT_HANDLE );
		wp_add_inline_style( self::FONT_HANDLE, $this->font_face() );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			CHATCHI_URL . 'assets/widget/widget.js',
			array(),
			CHATCHI_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.ChatChiWidget = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);
	}

	/**
	 * Returns the configuration handed to the widget script.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$config = array(
			'restUrl'  => esc_url_raw( rest_url( 'chatchi/v1' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'styleUrl' => esc_url_raw( add_query_arg( 'ver', CHATCHI_VERSION, CHATCHI_URL . 'assets/widget/widget.css' ) ),
			'position' => $this->position(),
			'colors'   => $this->palette->tokens(),
			'locale'   => str_replace( '_', '-', determine_locale() ),
			'dir'      => is_rtl() ? 'rtl' : 'ltr',
			'strings'  => $this->strings->all(),
		);

		/**
		 * Filters the widget configuration.
		 *
		 * @param array<string, mixed> $config Configuration.
		 */
		return (array) apply_filters( 'chatchi_widget_config', $config );
	}

	/**
	 * Returns which bottom corner the widget sits in.
	 */
	private function position(): string {
		/**
		 * Filters the widget corner: "left" or "right".
		 *
		 * @param string $position Corner.
		 */
		$position = apply_filters( 'chatchi_widget_position', 'left' );

		return 'right' === $position ? 'right' : 'left';
	}

	/**
	 * Returns the @font-face rule for the bundled Vazirmatn variable font.
	 *
	 * A plugin-specific family name avoids clashing with a theme that ships
	 * its own Vazirmatn. Browsers only download it once text uses it.
	 */
	private function font_face(): string {
		$url = esc_url( CHATCHI_URL . 'assets/fonts/vazirmatn/Vazirmatn-Variable.woff2' );

		return "@font-face{font-family:'ChatChi Vazirmatn';src:url('{$url}') format('woff2-variations'),url('{$url}') format('woff2');font-weight:100 900;font-style:normal;font-display:swap;}";
	}
}
