<?php
/**
 * Front-end chat widget loader.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

use ChatChi\Core\Contracts\Module;
use ChatChi\Settings\ProactiveRules;
use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the widget script and its configuration on front-end pages.
 *
 * Whether a page gets the widget is decided by the display rules, a
 * [chatchi] shortcode on the page, and finally the chatchi_widget_enabled
 * filter.
 *
 * The page itself gets no markup: the script builds the widget inside a
 * Shadow DOM so theme styles cannot leak in or out. Nothing per-visitor is
 * printed into the page, so full-page caching keeps working; the only
 * per-user value is the REST nonce, and only for logged-in users, whose
 * pages are not cached.
 */
final class WidgetModule implements Module {

	public const SCRIPT_HANDLE = 'chatchi-widget';

	public const CORE_HANDLE = 'chatchi-core';

	public const FONT_HANDLE = 'chatchi-fonts';

	private ColorPalette $palette;

	private WidgetStrings $strings;

	private FontFace $font;

	private Settings $settings;

	private DisplayRules $rules;

	/**
	 * Whether a shortcode asked for the widget on this page.
	 */
	private bool $forced = false;

	/**
	 * Whether the assets were already enqueued.
	 */
	private bool $loaded = false;

	/**
	 * @param ColorPalette  $palette  Color tokens.
	 * @param WidgetStrings $strings  Interface text.
	 * @param FontFace      $font     Bundled font.
	 * @param Settings      $settings Plugin settings.
	 * @param DisplayRules  $rules    Where the widget appears.
	 */
	public function __construct( ColorPalette $palette, WidgetStrings $strings, FontFace $font, Settings $settings, DisplayRules $rules ) {
		$this->palette  = $palette;
		$this->strings  = $strings;
		$this->font     = $font;
		$this->settings = $settings;
		$this->rules    = $rules;
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

		// The master switch in Settings turns the widget off everywhere, shortcodes included.
		$enabled = $this->settings->get( 'widget_enabled' )
			&& ( $this->forced || $this->shortcode_on_page() || $this->rules->allows_current_page() );

		/**
		 * Filters whether the chat widget loads on the current page.
		 *
		 * @param bool $enabled Whether the display rules or a shortcode show the widget here.
		 */
		return (bool) apply_filters( 'chatchi_widget_enabled', $enabled );
	}

	/**
	 * Shows the widget on the current page regardless of the display rules.
	 *
	 * Called by the shortcode. When the page head was already printed, the
	 * assets are enqueued late and WordPress prints them in the footer.
	 */
	public function force(): void {
		$this->forced = true;

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueues the font face, script and configuration.
	 */
	public function enqueue(): void {
		if ( $this->loaded || ! $this->should_load() ) {
			return;
		}

		$this->loaded = true;

		// @font-face must live in the document; Shadow DOM styles cannot declare fonts.
		wp_register_style( self::FONT_HANDLE, false, array(), CHATCHI_VERSION );
		wp_enqueue_style( self::FONT_HANDLE );
		wp_add_inline_style( self::FONT_HANDLE, $this->font->css() );

		wp_register_script(
			self::CORE_HANDLE,
			CHATCHI_URL . 'assets/shared/core.js',
			array(),
			CHATCHI_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			CHATCHI_URL . 'assets/widget/widget.js',
			array( self::CORE_HANDLE ),
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
			'restUrl'   => esc_url_raw( rest_url( 'chatchi/v1' ) ),
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'styleUrls' => array(
				esc_url_raw( add_query_arg( 'ver', CHATCHI_VERSION, CHATCHI_URL . 'assets/shared/tokens.css' ) ),
				esc_url_raw( add_query_arg( 'ver', CHATCHI_VERSION, CHATCHI_URL . 'assets/widget/widget.css' ) ),
			),
			'position'  => $this->position(),
			'colors'    => $this->palette->tokens(),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'dir'       => is_rtl() ? 'rtl' : 'ltr',
			'welcome'   => $this->settings->welcome_message(),
			'sound'     => (bool) $this->settings->get( 'widget_sound' ),
			'rating'    => (bool) $this->settings->get( 'rating_enabled' ),
			// Chat invitations; the widget matches them against this page.
			'proactive' => ProactiveRules::active( (array) $this->settings->get( 'proactive' ) ),
			'pageType'  => $this->page_type(),
			// Administrators see technical details of failed requests in the
			// widget. Pages of logged-in users are not cached.
			'debug'     => is_user_logged_in() && current_user_can( 'manage_options' ),
			'strings'   => $this->strings(),
		);

		/**
		 * Filters the widget configuration.
		 *
		 * @param array<string, mixed> $config Configuration.
		 */
		return (array) apply_filters( 'chatchi_widget_config', $config );
	}

	/**
	 * Whether the post being viewed contains the shortcode in its content.
	 */
	private function shortcode_on_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();

		return $post instanceof \WP_Post && Shortcode::in( (string) $post->post_content );
	}

	/**
	 * Returns the interface text, with the title, subtitle and form
	 * introduction from the settings when the admin wrote their own.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		$strings = $this->strings->all();

		foreach ( array( 'title' => 'widget_title', 'subtitle' => 'widget_subtitle', 'formIntro' => 'form_intro' ) as $key => $setting ) {
			$custom = (string) $this->settings->get( $setting );

			if ( '' !== $custom ) {
				$strings[ $key ] = $custom;
			}
		}

		return $strings;
	}

	/**
	 * What kind of page this is, for chat invitations. The same for every
	 * visitor, so it is safe in cached pages.
	 */
	private function page_type(): string {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
			return 'shop';
		}
		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		return 'other';
	}

	/**
	 * Returns which bottom corner the widget sits in.
	 */
	private function position(): string {
		/**
		 * Filters the widget corner: "left" or "right".
		 *
		 * @param string $position Corner chosen in the settings.
		 */
		$position = apply_filters( 'chatchi_widget_position', $this->settings->get( 'widget_position' ) );

		return 'right' === $position ? 'right' : 'left';
	}
}
