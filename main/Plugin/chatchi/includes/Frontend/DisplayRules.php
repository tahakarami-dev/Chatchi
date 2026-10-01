<?php
/**
 * Where the chat widget appears.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides from the display settings whether the current page gets the widget.
 *
 * Modes:
 * - "all":     every front-end page.
 * - "include": only pages that match a chosen page type or item.
 * - "exclude": every page except those that match.
 *
 * Page types: the home page, all pages, all blog posts, and WooCommerce
 * products (single products, the shop and product categories and tags).
 */
final class DisplayRules {

	private Settings $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the rules allow the widget on the current page.
	 *
	 * Must be called after the main query has run (from wp_enqueue_scripts
	 * or later).
	 */
	public function allows_current_page(): bool {
		$mode = $this->settings->get( 'display_mode' );

		if ( 'all' === $mode ) {
			return true;
		}

		$matches = $this->matches_current_page(
			(array) $this->settings->get( 'display_targets' ),
			(array) $this->settings->get( 'display_ids' )
		);

		return 'include' === $mode ? $matches : ! $matches;
	}

	/**
	 * Whether the current page matches any of the page types or items.
	 *
	 * @param string[] $targets Page types.
	 * @param int[]    $ids     Post IDs.
	 */
	public function matches_current_page( array $targets, array $ids ): bool {
		foreach ( $targets as $target ) {
			if ( $this->matches_target( (string) $target ) ) {
				return true;
			}
		}

		return $ids && is_singular() && in_array( (int) get_queried_object_id(), array_map( 'intval', $ids ), true );
	}

	/**
	 * @param string $target Page type.
	 */
	private function matches_target( string $target ): bool {
		switch ( $target ) {
			case 'home':
				return is_front_page();

			case 'pages':
				return is_page();

			case 'posts':
				return is_singular( 'post' );

			case 'products':
				return $this->is_product_page();
		}

		return false;
	}

	/**
	 * Single products, the shop page and product category and tag archives.
	 */
	private function is_product_page(): bool {
		if ( ! post_type_exists( 'product' ) ) {
			return false;
		}

		if ( is_singular( 'product' ) || is_post_type_archive( 'product' ) ) {
			return true;
		}

		// WooCommerce's shop page is not always a product archive in the main
		// query (it can be a plain page), but WooCommerce knows it.
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		$taxonomies = array_values( array_intersect( array( 'product_cat', 'product_tag' ), get_object_taxonomies( 'product' ) ) );

		return $taxonomies && is_tax( $taxonomies );
	}
}
