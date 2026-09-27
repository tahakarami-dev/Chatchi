<?php
/**
 * Whether support is available right now.
 *
 * @package ChatChi
 */

namespace ChatChi\Availability;

use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Combines business hours and agent presence into one state:
 *
 * - "online":  within business hours and at least one agent is available.
 * - "away":    within business hours, but no agent is available right now.
 * - "offline": outside business hours, or no agent is available and the
 *              site treats that as offline.
 *
 * Offline visitors can leave a message (or keep chatting, per settings);
 * the team is emailed about it.
 */
final class Availability {

	public const ONLINE  = 'online';
	public const AWAY    = 'away';
	public const OFFLINE = 'offline';

	private Settings $settings;

	private AgentRepository $agents;

	/**
	 * Available agents, counted once per request.
	 */
	private ?int $available = null;

	/**
	 * @param Settings        $settings Plugin settings.
	 * @param AgentRepository $agents   Agent storage, to count available agents.
	 */
	public function __construct( Settings $settings, AgentRepository $agents ) {
		$this->settings = $settings;
		$this->agents   = $agents;
	}

	/**
	 * The current state, as sent to the widget.
	 *
	 * @param \DateTimeImmutable|null $now Moment to check; now by default.
	 * @return array{mode: string, agents_online: bool, in_hours: bool, opens_at: string|null, offline_form: bool, offline_message: string}
	 */
	public function state( ?\DateTimeImmutable $now = null ): array {
		$now      = $now ? $now->setTimezone( wp_timezone() ) : new \DateTimeImmutable( 'now', wp_timezone() );
		$settings = $this->settings->all();
		$hours    = ! empty( $settings['hours_enabled'] );
		$in_hours = ! $hours || Schedule::is_open( $settings['hours'], $now );
		$online   = $this->agents_available() > 0;

		if ( ! $in_hours || ( ! $online && ! empty( $settings['offline_no_agents'] ) ) ) {
			$mode = self::OFFLINE;
		} else {
			$mode = $online ? self::ONLINE : self::AWAY;
		}

		$opens_at = null;

		if ( $hours && ! $in_hours ) {
			$next     = Schedule::next_open( $settings['hours'], $now );
			$opens_at = $next ? gmdate( 'Y-m-d\TH:i:s\Z', $next->getTimestamp() ) : null;
		}

		$state = array(
			'mode'            => $mode,
			'agents_online'   => $online,
			'in_hours'        => $in_hours,
			'opens_at'        => $opens_at,
			'offline_form'    => self::OFFLINE === $mode && 'message' === $settings['offline_mode'],
			'offline_message' => $this->settings->offline_message(),
		);

		/**
		 * Filters the availability shown to visitors.
		 *
		 * @param array              $state State: mode ("online", "away", "offline"), agents_online,
		 *                                  in_hours, opens_at (ISO 8601 UTC or null),
		 *                                  offline_form, offline_message.
		 * @param \DateTimeImmutable $now   Moment checked.
		 */
		$filtered = apply_filters( 'chatchi_availability', $state, $now );

		return is_array( $filtered ) ? array_merge( $state, $filtered ) : $state;
	}

	/**
	 * Shortcut: the current mode.
	 */
	public function mode(): string {
		return $this->state()['mode'];
	}

	/**
	 * Counts available agents; 0 when the count fails.
	 */
	public function agents_available(): int {
		if ( null === $this->available ) {
			try {
				$this->available = $this->agents->count_available();
			} catch ( \Throwable $e ) {
				Failure::report( $e, 'Availability', 'chatchi_unavailable' );
				$this->available = 0;
			}
		}

		return $this->available;
	}
}
