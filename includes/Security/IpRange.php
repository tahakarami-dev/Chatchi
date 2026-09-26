<?php
/**
 * IP address and CIDR range helpers.
 *
 * @package ChatChi
 */

namespace ChatChi\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Parses, normalizes and matches IPv4/IPv6 addresses and CIDR ranges.
 */
final class IpRange {

	/**
	 * Returns a normalized address, or null when the text is not an IP.
	 *
	 * @param string $ip Candidate address.
	 */
	public static function ip( string $ip ): ?string {
		$ip = trim( $ip );

		// "[2001:db8::1]" or "1.2.3.4:5678" as some proxies send them.
		if ( preg_match( '/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $ip, $m ) ) {
			$ip = $m[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m ) ) {
			$ip = $m[1];
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		return false === $packed ? null : (string) inet_ntop( $packed );
	}

	/**
	 * Returns a normalized "address/bits" range (or a single address), or
	 * null when the text is neither.
	 *
	 * @param string $range Candidate, e.g. "173.245.48.0/20" or "2400:cb00::/32".
	 */
	public static function range( string $range ): ?string {
		$range = trim( $range );

		if ( false === strpos( $range, '/' ) ) {
			return self::ip( $range );
		}

		list( $address, $bits ) = explode( '/', $range, 2 );
		$address                 = self::ip( $address );

		if ( null === $address || ! ctype_digit( $bits ) ) {
			return null;
		}

		$max = false === strpos( $address, ':' ) ? 32 : 128;

		return (int) $bits <= $max ? $address . '/' . (int) $bits : null;
	}

	/**
	 * Whether an address falls inside any of the ranges.
	 *
	 * @param string   $ip     Normalized address.
	 * @param string[] $ranges Normalized ranges or addresses.
	 */
	public static function in_any( string $ip, array $ranges ): bool {
		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			list( $address, $bits ) = array_pad( explode( '/', (string) $range, 2 ), 2, null );
			$network                = inet_pton( (string) $address );

			if ( false === $network || strlen( $network ) !== strlen( $packed ) ) {
				continue;
			}

			$bits = null === $bits ? strlen( $packed ) * 8 : (int) $bits;

			if ( self::prefix( $packed, $bits ) === self::prefix( $network, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the subject used to rate-limit an address: IPv4 addresses as
	 * they are, IPv6 addresses by their /64 network, since one customer is
	 * usually given a whole /64.
	 *
	 * @param string $ip Normalized address.
	 */
	public static function subject( string $ip ): string {
		$packed = inet_pton( $ip );

		if ( false === $packed || 4 === strlen( $packed ) ) {
			return $ip;
		}

		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Returns the first $bits bits of a packed address, other bits zeroed.
	 *
	 * @param string $packed Packed address.
	 * @param int    $bits   Prefix length.
	 */
	private static function prefix( string $packed, int $bits ): string {
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		$out   = substr( $packed, 0, $bytes );

		if ( $rest ) {
			$out .= chr( ord( $packed[ $bytes ] ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF );
		}

		return $out;
	}
}
