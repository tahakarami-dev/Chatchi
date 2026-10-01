<?php
/**
 * Who may open the agent panel.
 *
 * @package ChatChi
 */

namespace ChatChi\Admin;

use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Models\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Grants the virtual `chatchi_access_panel` capability.
 *
 * It is not stored on any role: it is computed from the agents table, so
 * activating or deactivating an agent takes effect immediately. Site
 * administrators always have it and get an agent record on first visit, so
 * the panel works out of the box.
 */
final class PanelAccess {

	public const CAPABILITY = 'chatchi_access_panel';

	private AgentRepository $agents;

	/**
	 * Per-request cache of agent lookups, keyed by user ID.
	 *
	 * @var array<int, Agent|null>
	 */
	private array $cache = array();

	/**
	 * @param AgentRepository $agents Agent storage.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * `user_has_cap` filter: resolves the panel capability.
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @param string[]            $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Original arguments.
	 * @param \WP_User            $user    User.
	 * @return array<string, bool>
	 */
	public function grant( $allcaps, $caps, $args, $user ) {
		if ( ! is_array( $caps ) || ! in_array( self::CAPABILITY, $caps, true ) || ! $user instanceof \WP_User ) {
			return $allcaps;
		}

		$allcaps[ self::CAPABILITY ] = ! empty( $allcaps['manage_options'] ) || $this->is_active_agent( $user->ID );

		return $allcaps;
	}

	/**
	 * Gives a site administrator an active agent record if they have none.
	 *
	 * An existing record is never changed, so a deactivated administrator
	 * stays deactivated.
	 *
	 * @param \WP_User $user Current user.
	 * @return Agent|null The user's agent record.
	 */
	public function ensure_admin_agent( \WP_User $user ): ?Agent {
		$agent = $this->agent( $user->ID );

		if ( $agent || ! user_can( $user, 'manage_options' ) ) {
			return $agent;
		}

		try {
			$agent = $this->agents->create( $user->ID, $user->display_name ? $user->display_name : $user->user_login );
		} catch ( \Throwable $e ) {
			// A concurrent request may have created it; read it back.
			unset( $this->cache[ $user->ID ] );
			$agent = $this->agent( $user->ID );
		}

		$this->cache[ $user->ID ] = $agent;

		return $agent;
	}

	/**
	 * @param int $user_id WordPress user ID.
	 */
	private function is_active_agent( int $user_id ): bool {
		$agent = $this->agent( $user_id );

		return null !== $agent && $agent->is_active;
	}

	/**
	 * @param int $user_id WordPress user ID.
	 */
	private function agent( int $user_id ): ?Agent {
		if ( ! array_key_exists( $user_id, $this->cache ) ) {
			try {
				$this->cache[ $user_id ] = $this->agents->find_by_user_id( $user_id );
			} catch ( \Throwable $e ) {
				$this->cache[ $user_id ] = null;
			}
		}

		return $this->cache[ $user_id ];
	}
}
