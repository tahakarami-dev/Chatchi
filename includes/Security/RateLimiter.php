<?php
/**
 * Request rate limiting.
 *
 * @package ChatChi
 */

namespace ChatChi\Security;

use ChatChi\Database\Repositories\RateLimitRepository;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Limits how often visitors may start conversations and send messages.
 *
 * Each action has limits per visitor and per IP address. Counting uses a
 * sliding window: the current window's hits plus the previous window's,
 * weighted by how much of it still overlaps. That stops bursts at window
 * edges without storing every request.
 *
 * Attempts are counted before they are judged, including rejected ones, so
 * a client that keeps hammering stays blocked until it slows down. Subjects
 * are stored only as salted hashes.
 *
 * If the counter table cannot be used, requests are allowed: an outage of
 * this protection must not take the chat down.
 */
final class RateLimiter {

	public const PURGE_HOOK = 'chatchi_purge_rate_limits';

	private RateLimitRepository $counters;

	private ClientIp $client_ip;

	private ?Settings $settings;

	/**
	 * @param RateLimitRepository $counters  Hit counters.
	 * @param ClientIp            $client_ip Visitor address.
	 * @param Settings|null       $settings  Plugin settings; without them the built-in limits apply.
	 */
	public function __construct( RateLimitRepository $counters, ClientIp $client_ip, ?Settings $settings = null ) {
		$this->counters  = $counters;
		$this->client_ip = $client_ip;
		$this->settings  = $settings;
	}

	/**
	 * Returns the limits: action => scope => [max hits, window in seconds].
	 *
	 * @return array<string, array<string, array{0: int, 1: int}>>
	 */
	public function limits(): array {
		$defaults = array(
			// Starting a conversation creates a visitor and a conversation.
			'start'   => array(
				'ip'      => array( $this->setting( 'limit_starts_ip', 10 ), 10 * MINUTE_IN_SECONDS ),
				'visitor' => array( $this->setting( 'limit_starts_visitor', 5 ), HOUR_IN_SECONDS ),
			),
			'message' => array(
				'visitor' => array( $this->setting( 'limit_messages_visitor', 20 ), MINUTE_IN_SECONDS ),
				'ip'      => array( $this->setting( 'limit_messages_ip', 60 ), MINUTE_IN_SECONDS ),
			),
			// Typing signals: the widget sends one every few seconds while typing.
			'typing'  => array(
				'visitor' => array( 40, MINUTE_IN_SECONDS ),
				'ip'      => array( 240, MINUTE_IN_SECONDS ),
			),
			// Files: each one is also stored on disk.
			'upload'  => array(
				'visitor' => array( 10, 10 * MINUTE_IN_SECONDS ),
				'ip'      => array( 40, 10 * MINUTE_IN_SECONDS ),
			),
			// Marking messages read and closing.
			'write'   => array(
				'visitor' => array( 60, MINUTE_IN_SECONDS ),
				'ip'      => array( 180, MINUTE_IN_SECONDS ),
			),
		);

		/**
		 * Filters the rate limits.
		 *
		 * Shape: action ("start", "message", "typing", "upload", "write") => scope ("visitor",
		 * "ip") => array( max hits, window in seconds ). Remove a scope or
		 * set its max to 0 to turn it off.
		 *
		 * Per-IP limits are generous on purpose: offices and mobile carriers
		 * put many people behind one address.
		 *
		 * @param array $limits Limits.
		 */
		$limits = apply_filters( 'chatchi_rate_limits', $defaults );

		return is_array( $limits ) ? $limits : $defaults;
	}

	/**
	 * Returns a limit from the settings.
	 *
	 * @param string $key      Setting name.
	 * @param int    $fallback Limit without settings.
	 */
	private function setting( string $key, int $fallback ): int {
		return $this->settings ? (int) $this->settings->get( $key ) : $fallback;
	}

	/**
	 * Counts an attempt and says whether it is allowed.
	 *
	 * @param string   $action     "start", "message", "typing", "upload" or "write".
	 * @param int|null $visitor_id Visitor, when known.
	 * @return \WP_Error|null A 429 error with data.retry_after, or null.
	 */
	public function check( string $action, ?int $visitor_id ): ?\WP_Error {
		$limits = $this->limits()[ $action ] ?? array();

		if ( ! is_array( $limits ) || ! $limits ) {
			return null;
		}

		$ip       = $this->client_ip->get();
		$subjects = array(
			'visitor' => $visitor_id && $visitor_id > 0 ? 'v' . $visitor_id : '',
			'ip'      => '' !== $ip ? 'ip' . IpRange::subject( $ip ) : '',
		);

		$retry = 0;
		$now   = time();

		try {
			foreach ( $limits as $scope => $limit ) {
				if ( ! isset( $subjects[ $scope ] ) || '' === $subjects[ $scope ] || ! is_array( $limit ) || count( $limit ) < 2 ) {
					continue;
				}

				$max    = (int) $limit[0];
				$window = (int) $limit[1];

				if ( $max < 1 || $window < 1 ) {
					continue;
				}

				$wait = $this->hit( $action . '|' . $scope . '|' . $subjects[ $scope ], $max, $window, $now );

				if ( $wait > 0 ) {
					/**
					 * Fires when a request is refused by a rate limit.
					 *
					 * @param string $action Action.
					 * @param string $scope  "visitor" or "ip".
					 * @param int    $wait   Seconds until another attempt may pass.
					 */
					do_action( 'chatchi_rate_limited', $action, (string) $scope, $wait );
					$retry = max( $retry, $wait );
				}
			}
		} catch ( \Throwable $e ) {
			// A missing counter table means an upgrade did not finish: repair on the next request.
			Failure::repair_if_needed( $e );

			/**
			 * Fires when the rate limiter could not reach its counters.
			 *
			 * The request is allowed.
			 *
			 * @param \Throwable $e Error.
			 */
			do_action( 'chatchi_rate_limit_error', $e );
			return null;
		}

		if ( 0 === $retry ) {
			return null;
		}

		return new \WP_Error(
			'chatchi_rate_limited',
			__( 'Too many requests. Please wait a moment and try again.', 'chatchi' ),
			array(
				'status'      => 429,
				'retry_after' => $retry,
			)
		);
	}

	/**
	 * Deletes expired counters. Runs hourly from WP-Cron.
	 */
	public function purge(): void {
		try {
			$this->counters->purge( time() );
		} catch ( \Throwable $e ) {
			do_action( 'chatchi_rate_limit_error', $e );
		}
	}

	/**
	 * Records a hit and returns 0 when allowed, or the seconds to wait.
	 *
	 * @param string $key    Action, scope and subject.
	 * @param int    $max    Allowed hits per window.
	 * @param int    $window Window length in seconds.
	 * @param int    $now    Current Unix time.
	 */
	private function hit( string $key, int $max, int $window, int $now ): int {
		$index   = intdiv( $now, $window );
		$elapsed = $now - $index * $window;
		$current = $this->bucket( $key, $window, $index );
		$prior   = $this->bucket( $key, $window, $index - 1 );

		// Kept for two windows: its own, and the next one that still reads it.
		$this->counters->hit( $current, ( $index + 2 ) * $window );

		$counts = $this->counters->counts( array( $current, $prior ) );
		$cur    = $counts[ $current ];
		$prev   = $counts[ $prior ];
		$weight = ( $window - $elapsed ) / $window;

		if ( $prev * $weight + $cur <= $max ) {
			return 0;
		}

		// When may the next attempt pass? Before it is counted, the estimate
		// has to drop to $max - 1.
		$target = $max - 1;

		if ( $cur <= $target && $prev > 0 ) {
			// Later in this window, as the previous window's weight fades.
			$wait = (int) ceil( $window * ( 1 - ( $target - $cur ) / $prev ) - $elapsed );
		} else {
			// In the next window, as this window's hits fade.
			$wait = ( $window - $elapsed ) + (int) ceil( $window * ( 1 - $target / $cur ) );
		}

		return max( 1, min( $wait, 2 * $window ) );
	}

	/**
	 * @param string $key    Action, scope and subject.
	 * @param int    $window Window length.
	 * @param int    $index  Window number.
	 */
	private function bucket( string $key, int $window, int $index ): string {
		return hash_hmac( 'sha256', $key . '|' . $window . '|' . $index, wp_salt( 'auth' ) );
	}
}
