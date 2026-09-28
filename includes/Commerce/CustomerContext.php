<?php
/**
 * What the visitor is doing, and their shop history, for agents.
 *
 * @package ChatChi
 */

namespace ChatChi\Commerce;

use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Visitor;
use ChatChi\Support\Failure;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Tells agents where a visitor is and, on WooCommerce shops, what is in
 * their cart and what they ordered before.
 *
 * The page and cart are recorded when the widget loads on a page, only for
 * visitors who already chatted. The cart is read on the server from the
 * visitor's own WooCommerce session: it is never put into the page, so
 * cached pages cannot show one shopper's cart to another.
 */
final class CustomerContext {

	private const MAX_CART_ITEMS = 20;

	private const RECENT_ORDERS = 5;

	private VisitorRepository $visitors;

	/**
	 * @param VisitorRepository $visitors Visitor storage.
	 */
	public function __construct( VisitorRepository $visitors ) {
		$this->visitors = $visitors;
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public function has_shop(): bool {
		return function_exists( 'WC' ) && function_exists( 'wc_get_orders' );
	}

	/**
	 * Records the page a visitor is on, and their cart.
	 *
	 * @param Visitor $visitor Visitor.
	 * @param string  $url     Page address (only this site's pages are kept).
	 * @param string  $title   Page title.
	 */
	public function record( Visitor $visitor, string $url, string $title ): void {
		$url = $this->same_site_url( $url );

		if ( '' === $url ) {
			return;
		}

		try {
			$this->visitors->set_context( $visitor->id, $url, mb_substr( PlainText::line( $title ), 0, 255 ), $this->cart() );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Context', 'chatchi_unavailable' );
		}
	}

	/**
	 * The visitor's context for the agent panel.
	 *
	 * @param Visitor|null $visitor     Visitor.
	 * @param bool         $with_orders Include orders (only for people who may see them).
	 * @return array<string, mixed>
	 */
	public function for_agent( ?Visitor $visitor, bool $with_orders ): array {
		$data = array(
			'page'       => null,
			'shop'       => $this->has_shop(),
			'cart'       => null,
			'orders'     => null,
			'can_orders' => $with_orders,
		);

		if ( ! $visitor ) {
			return $data;
		}

		if ( '' !== $visitor->current_url ) {
			$data['page'] = array(
				'url'   => $visitor->current_url,
				'title' => $visitor->current_title,
				'at'    => $visitor->context_at ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $visitor->context_at . ' UTC' ) ) : null,
			);
		}

		if ( ! $this->has_shop() ) {
			return $data;
		}

		if ( $visitor->cart ) {
			$data['cart'] = $this->present_cart( $visitor->cart );
		}

		if ( $with_orders ) {
			$data['orders'] = $this->orders( $visitor );
		}

		return $data;
	}

	/**
	 * The current request's WooCommerce cart, summarized; null when there
	 * is no shop or the cart cannot be read here.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cart(): ?array {
		if ( ! $this->has_shop() ) {
			return null;
		}

		try {
			// REST requests do not load the cart by default; this reads the
			// visitor's session from their own cookie.
			if ( function_exists( 'wc_load_cart' ) && ( ! isset( WC()->cart ) || ! WC()->cart ) ) {
				wc_load_cart();
			}

			$cart = WC()->cart;

			if ( ! $cart ) {
				return null;
			}

			$items = array();

			foreach ( array_slice( $cart->get_cart(), 0, self::MAX_CART_ITEMS ) as $item ) {
				$product = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;

				$items[] = array(
					'product_id' => (int) ( $item['product_id'] ?? 0 ),
					'name'       => $product ? PlainText::line( (string) $product->get_name() ) : '',
					'quantity'   => (int) ( $item['quantity'] ?? 0 ),
					'total'      => (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ),
				);
			}

			return array(
				'items'    => $items,
				'count'    => (int) $cart->get_cart_contents_count(),
				'total'    => (float) $cart->get_total( 'edit' ),
				'currency' => get_woocommerce_currency(),
			);
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Context', 'chatchi_unavailable' );
			return null;
		}
	}

	/**
	 * A stored cart with prices formatted in the shop's currency.
	 *
	 * @param array<string, mixed> $cart Stored cart.
	 * @return array<string, mixed>
	 */
	private function present_cart( array $cart ): array {
		$items = array();

		foreach ( (array) ( $cart['items'] ?? array() ) as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );

			$items[] = array(
				'name'     => (string) ( $item['name'] ?? '' ),
				'quantity' => (int) ( $item['quantity'] ?? 0 ),
				'total'    => $this->money( (float) ( $item['total'] ?? 0 ) ),
				'url'      => $product_id > 0 ? (string) get_permalink( $product_id ) : '',
			);
		}

		return array(
			'items' => $items,
			'count' => (int) ( $cart['count'] ?? 0 ),
			'total' => $this->money( (float) ( $cart['total'] ?? 0 ) ),
		);
	}

	/**
	 * The visitor's recent orders and how much they spent, found by their
	 * account or, for guests, their email address.
	 *
	 * @param Visitor $visitor Visitor.
	 * @return array<string, mixed>
	 */
	private function orders( Visitor $visitor ): array {
		$customers = array_values( array_filter( array( $visitor->user_id > 0 ? $visitor->user_id : null, is_email( $visitor->email ) ? $visitor->email : null ) ) );
		$result    = array(
			'items' => array(),
			'count' => 0,
			'spent' => $this->money( 0 ),
		);

		if ( ! $customers ) {
			return $result;
		}

		try {
			$orders = wc_get_orders(
				array(
					'customer' => $customers,
					'limit'    => 100,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'type'     => 'shop_order',
				)
			);
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Context', 'chatchi_unavailable' );
			return $result;
		}

		$spent = 0.0;

		foreach ( $orders as $order ) {
			if ( $order->is_paid() ) {
				$spent += (float) $order->get_total();
			}
		}

		foreach ( array_slice( $orders, 0, self::RECENT_ORDERS ) as $order ) {
			$names = array();

			foreach ( $order->get_items() as $item ) {
				$names[] = PlainText::line( (string) $item->get_name() ) . ( $item->get_quantity() > 1 ? ' × ' . $item->get_quantity() : '' );
			}

			$created = $order->get_date_created();

			$result['items'][] = array(
				'id'           => $order->get_id(),
				'number'       => (string) $order->get_order_number(),
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
				'total'        => $this->money( (float) $order->get_total() ),
				'date'         => $created ? gmdate( 'Y-m-d\TH:i:s\Z', $created->getTimestamp() ) : null,
				'items'        => array_slice( $names, 0, 5 ),
				'url'          => current_user_can( 'edit_shop_orders' ) ? $order->get_edit_order_url() : '',
			);
		}

		$result['count'] = count( $orders );
		$result['spent'] = $this->money( $spent );

		return $result;
	}

	/**
	 * A price as plain text in the shop's format, e.g. "۱۲۰٬۰۰۰ تومان".
	 *
	 * @param float $amount Amount.
	 */
	private function money( float $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Keeps an address only if it is an http(s) page of this site.
	 *
	 * @param string $raw Raw address.
	 */
	private function same_site_url( string $raw ): string {
		$url = esc_url_raw( trim( $raw ), array( 'http', 'https' ) );

		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$site = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $site ) && strtolower( $host ) === strtolower( $site ) ? $url : '';
	}
}
