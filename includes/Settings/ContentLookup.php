<?php
/**
 * Finds pages, posts and products for display rules.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the published content the widget display rules can point at.
 */
final class ContentLookup {

	/**
	 * Content types that can be picked, in display order. Products are only
	 * offered while WooCommerce (or another plugin) registers them.
	 */
	private const POST_TYPES = array( 'page', 'post', 'product' );

	private const SEARCH_LIMIT = 20;

	/**
	 * Returns the content types available on this site.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		return array_values( array_filter( self::POST_TYPES, 'post_type_exists' ) );
	}

	/**
	 * Whether WooCommerce products exist on this site.
	 */
	public function has_products(): bool {
		return post_type_exists( 'product' );
	}

	/**
	 * Searches published content by title, newest first.
	 *
	 * @param string $term Search text; empty lists the latest items.
	 * @return \WP_Post[]
	 */
	public function search( string $term ): array {
		$args = array(
			'post_type'              => $this->post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => self::SEARCH_LIMIT,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		$term = trim( $term );

		if ( '' !== $term ) {
			$args['s']              = $term;
			// Titles only (WordPress 6.2+; older versions also search the content).
			$args['search_columns'] = array( 'post_title' );
			$args['orderby']        = 'relevance';
		}

		return array_values( array_filter( ( new \WP_Query( $args ) )->posts, array( $this, 'is_pickable' ) ) );
	}

	/**
	 * Returns the pickable items among the given IDs, in the given order.
	 *
	 * Deleted, unpublished and unsupported items are left out.
	 *
	 * @param int[] $ids Post IDs.
	 * @return \WP_Post[]
	 */
	public function find( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, false );

		$posts = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post && $this->is_pickable( $post ) ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Returns the singular label of a post's type, e.g. "Page".
	 *
	 * @param \WP_Post $post Post.
	 */
	public function type_label( \WP_Post $post ): string {
		$type = get_post_type_object( $post->post_type );

		return $type ? (string) $type->labels->singular_name : $post->post_type;
	}

	/**
	 * @param mixed $post Candidate.
	 */
	private function is_pickable( $post ): bool {
		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, $this->post_types(), true );
	}
}
