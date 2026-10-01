<?php
/**
 * Visitor IP address.
 *
 * @package ChatChi
 */

namespace ChatChi\Security;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Works out the visitor's IP address.
 *
 * By default only the address of the connection (REMOTE_ADDR) is used; it
 * cannot be forged. When the site sits behind Cloudflare or another proxy
 * the connection comes from the proxy, which forwards the visitor's address
 * in a header. That header is only believed when the connection comes from
 * one of the trusted proxy addresses, because anyone reaching the server
 * directly could send it with any value.
 */
final class ClientIp {

	public const HEADER_CLOUDFLARE = 'CF-Connecting-IP';

	/**
	 * Cloudflare's published address ranges (https://www.cloudflare.com/ips/).
	 */
	public const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	private Settings $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns the visitor's address, or an empty string when unknown.
	 */
	public function get(): string {
		$remote = $this->remote_addr();
		$ip     = '' !== $remote ? $this->forwarded( $remote ) : '';

		/**
		 * Filters the visitor's IP address used for rate limiting.
		 *
		 * @param string $ip     Address worked out from the settings.
		 * @param string $remote Address of the connection itself.
		 */
		$filtered = apply_filters( 'chatchi_client_ip', $ip, $remote );

		return is_string( $filtered ) ? (string) IpRange::ip( $filtered ) : $ip;
	}

	/**
	 * Returns the address of the connection itself.
	 */
	public function remote_addr(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.

		return (string) IpRange::ip( $remote );
	}

	/**
	 * Returns the header and trusted ranges for the configured mode, or
	 * null when visitors connect directly.
	 *
	 * @return array{header: string, trusted: string[]}|null
	 */
	public function proxy(): ?array {
		$mode = $this->settings->get( 'proxy_mode' );

		if ( 'cloudflare' === $mode ) {
			return array(
				'header'  => self::HEADER_CLOUDFLARE,
				'trusted' => self::CLOUDFLARE_RANGES,
			);
		}

		if ( 'custom' === $mode && '' !== (string) $this->settings->get( 'proxy_header' ) ) {
			return array(
				'header'  => (string) $this->settings->get( 'proxy_header' ),
				'trusted' => (array) $this->settings->get( 'trusted_proxies' ),
			);
		}

		return null;
	}

	/**
	 * @param string $remote Connection address.
	 */
	private function forwarded( string $remote ): string {
		$proxy = $this->proxy();

		if ( ! $proxy ) {
			return $remote;
		}

		$trusted = $proxy['trusted'];

		// With a list, only proxies on it may speak for the visitor.
		if ( $trusted && ! IpRange::in_any( $remote, $trusted ) ) {
			return $remote;
		}

		$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $proxy['header'] ) );
		$value = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each entry is validated as an IP.

		if ( '' === $value ) {
			return $remote;
		}

		// Lists like X-Forwarded-For grow to the right; the right-most entry
		// that is not one of our own proxies is the one we can trust.
		$entries = array_reverse( explode( ',', $value ) );

		foreach ( $entries as $entry ) {
			$ip = IpRange::ip( $entry );

			if ( null === $ip ) {
				return $remote;
			}

			if ( $trusted && IpRange::in_any( $ip, $trusted ) ) {
				continue;
			}

			return $ip;
		}

		return $remote;
	}
}
