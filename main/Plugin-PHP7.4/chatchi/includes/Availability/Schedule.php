<?php
/**
 * Weekly business hours.
 *
 * @package ChatChi
 */

namespace ChatChi\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * A week of opening hours: one entry per weekday, keyed by PHP's day
 * number ("0" = Sunday … "6" = Saturday), each with an on/off switch and a
 * from/to time ("HH:MM", 24-hour, in the site's time zone).
 *
 * A day closes at its "to" time; "24:00" means until midnight. Hours that
 * run past midnight are entered as two days.
 */
final class Schedule {

	public const DAYS = array( '0', '1', '2', '3', '4', '5', '6' );

	/**
	 * The default week: Saturday to Wednesday 9–17, Thursday 9–13, Friday
	 * closed, as common in Iran.
	 *
	 * @return array<string, array{on: bool, from: string, to: string}>
	 */
	public static function defaults(): array {
		$week = array();

		foreach ( self::DAYS as $day ) {
			$week[ $day ] = array(
				'on'   => '5' !== $day,
				'from' => '09:00',
				'to'   => '4' === $day ? '13:00' : '17:00',
			);
		}

		return $week;
	}

	/**
	 * Validates a week strictly, for saving.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, array{on: bool, from: string, to: string}>|null Null when invalid.
	 */
	public static function clean( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$week = array();

		foreach ( self::DAYS as $day ) {
			$entry = $value[ $day ] ?? $value[ (int) $day ] ?? null;

			if ( ! is_array( $entry ) || ! isset( $entry['from'], $entry['to'] ) ) {
				return null;
			}

			$from = self::time( $entry['from'] );
			$to   = self::time( $entry['to'] );
			$on   = rest_sanitize_boolean( $entry['on'] ?? false );

			if ( null === $from || null === $to || ( $on && self::minutes( $from ) >= self::minutes( $to ) ) ) {
				return null;
			}

			$week[ $day ] = array(
				'on'   => $on,
				'from' => $from,
				'to'   => $to,
			);
		}

		return $week;
	}

	/**
	 * Brings a stored week back into shape; malformed days fall back to
	 * their defaults.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string, array{on: bool, from: string, to: string}>
	 */
	public static function repair( $value ): array {
		$defaults = self::defaults();
		$week     = array();

		foreach ( self::DAYS as $day ) {
			$entry = is_array( $value ) && isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
			// Checked on its own: the other days are filled with valid defaults.
			$clean = self::clean( array( $day => $entry ) + $defaults );

			$week[ $day ] = $clean ? $clean[ $day ] : $defaults[ $day ];
		}

		return $week;
	}

	/**
	 * Whether the schedule is open at a moment.
	 *
	 * @param array<string, array{on: bool, from: string, to: string}> $week Week.
	 * @param \DateTimeImmutable                                        $now  Moment, in the site's time zone.
	 */
	public static function is_open( array $week, \DateTimeImmutable $now ): bool {
		$entry = $week[ $now->format( 'w' ) ] ?? null;

		if ( ! $entry || ! $entry['on'] ) {
			return false;
		}

		$minute = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		return $minute >= self::minutes( $entry['from'] ) && $minute < self::minutes( $entry['to'] );
	}

	/**
	 * The next moment the schedule opens, within a week.
	 *
	 * @param array<string, array{on: bool, from: string, to: string}> $week Week.
	 * @param \DateTimeImmutable                                        $now  Moment, in the site's time zone.
	 * @return \DateTimeImmutable|null Null when no day is open.
	 */
	public static function next_open( array $week, \DateTimeImmutable $now ): ?\DateTimeImmutable {
		for ( $offset = 0; $offset <= 7; $offset++ ) {
			$day   = $now->modify( '+' . $offset . ' days' );
			$entry = $week[ $day->format( 'w' ) ] ?? null;

			if ( ! $entry || ! $entry['on'] ) {
				continue;
			}

			list( $hour, $minute ) = array_map( 'intval', explode( ':', $entry['from'] ) );

			$opens = $day->setTime( $hour, $minute );

			if ( $opens > $now ) {
				return $opens;
			}
		}

		return null;
	}

	/**
	 * Normalizes "9:5", "09:05" or "24:00" to "HH:MM"; null when invalid.
	 *
	 * @param mixed $value Raw time.
	 */
	private static function time( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $value ), $m ) ) {
			return null;
		}

		$hour   = (int) $m[1];
		$minute = (int) $m[2];

		if ( $minute > 59 || $hour > 24 || ( 24 === $hour && 0 !== $minute ) ) {
			return null;
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * @param string $time "HH:MM".
	 */
	private static function minutes( string $time ): int {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );

		return $hour * 60 + $minute;
	}
}
