<?php
/**
 * Bot protection for the start form.
 *
 * @package ChatChi
 */

namespace ChatChi\Security;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stops simple bots from starting conversations, invisibly for people.
 *
 * Two checks:
 * 1. Honeypot: the form has a field people never see. Bots that fill in
 *    every field reveal themselves.
 * 2. Timing: the widget receives a signed timestamp when it loads (from the
 *    session endpoint, which is never cached) and sends it back when starting
 *    a conversation. Starting sooner than a person could fill in the form is
 *    refused; the widget then simply waits and retries, so a real visitor
 *    who is quick never sees an error.
 */
final class SpamGuard {

	public const TRAP_FIELD = 'website';

	private const TOKEN_LIFETIME = DAY_IN_SECONDS;

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings; without them the built-in defaults apply.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Whether the checks are on.
	 */
	public function enabled(): bool {
		/**
		 * Filters whether the start form is protected against bots.
		 *
		 * Turn off for trusted API clients that start conversations without
		 * the widget.
		 *
		 * @param bool $enabled Whether to check.
		 */
		$default = $this->settings ? (bool) $this->settings->get( 'spam_protection' ) : true;

		return (bool) apply_filters( 'chatchi_spam_protection', $default );
	}

	/**
	 * Returns the minimum seconds between loading the widget and starting.
	 */
	public function min_seconds(): int {
		/**
		 * Filters how many seconds must pass between loading the widget and
		 * starting a conversation. Clamped to 0–60.
		 *
		 * @param int $seconds Seconds.
		 */
		return min( 60, max( 0, (int) apply_filters( 'chatchi_spam_min_seconds', 2 ) ) );
	}

	/**
	 * Issues a form token for the current time.
	 */
	public function token(): string {
		$time = (string) time();

		return $time . '.' . $this->sign( $time );
	}

	/**
	 * Checks a start request.
	 *
	 * @param string $token Form token sent by the widget.
	 * @param string $trap  Honeypot field value.
	 * @return \WP_Error|null
	 */
	public function check( string $token, string $trap ): ?\WP_Error {
		if ( ! $this->enabled() ) {
			return null;
		}

		if ( '' !== trim( $trap ) ) {
			/**
			 * Fires when a bot is caught by the start form checks.
			 *
			 * @param string $reason "honeypot".
			 */
			do_action( 'chatchi_spam_blocked', 'honeypot' );

			return new \WP_Error(
				'chatchi_spam',
				__( 'We could not start the chat. Please try again.', 'chatchi' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $m ) || ! hash_equals( $this->sign( $m[1] ), $m[2] ) ) {
			return $this->expired();
		}

		$age = time() - (int) $m[1];

		if ( $age < 0 || $age > self::TOKEN_LIFETIME ) {
			return $this->expired();
		}

		$min = $this->min_seconds();

		if ( $age < $min ) {
			return new \WP_Error(
				'chatchi_too_fast',
				__( 'Please take a moment before starting the chat.', 'chatchi' ),
				array(
					'status'      => 400,
					'retry_after' => $min - $age,
				)
			);
		}

		return null;
	}

	private function expired(): \WP_Error {
		return new \WP_Error(
			'chatchi_form_expired',
			__( 'The chat form expired. Please try again.', 'chatchi' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * @param string $time Unix time as text.
	 */
	private function sign( string $time ): string {
		return hash_hmac( 'sha256', 'chatchi-form|' . $time, wp_salt( 'nonce' ) );
	}
}
