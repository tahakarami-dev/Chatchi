<?php
/**
 * Agent management use cases.
 *
 * @package ChatChi
 */

namespace ChatChi\Agents;

use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Models\Agent;
use ChatChi\Support\Failure;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Creating agents from WordPress users, renaming them, setting their
 * picture and switching them on or off.
 *
 * Agents are deactivated rather than deleted, so their past messages keep a
 * name. Permission checks (site administrators only) happen in the REST
 * controller; this service enforces the data rules. Methods return WP_Error
 * instead of throwing.
 */
final class AgentService {

	private const NAME_MIN_LENGTH = 2;

	private const NAME_MAX_LENGTH = 100;

	private const CANDIDATE_LIMIT = 20;

	private AgentRepository $agents;

	private AvatarUploader $avatars;

	/**
	 * @param AgentRepository $agents  Agent storage.
	 * @param AvatarUploader  $avatars Avatar uploads.
	 */
	public function __construct( AgentRepository $agents, AvatarUploader $avatars ) {
		$this->agents  = $agents;
		$this->avatars = $avatars;
	}

	/**
	 * Lists every agent with its WordPress user.
	 *
	 * @return array<int, array{agent: Agent, user: \WP_User|null}>|\WP_Error
	 */
	public function all() {
		try {
			$agents = $this->agents->all();
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		$users = $this->users( array_map( static fn( Agent $agent ): int => $agent->user_id, $agents ) );

		return array_map(
			static function ( Agent $agent ) use ( $users ): array {
				return array(
					'agent' => $agent,
					'user'  => $users[ $agent->user_id ] ?? null,
				);
			},
			$agents
		);
	}

	/**
	 * Turns a WordPress user into an agent.
	 *
	 * @param int   $user_id      WordPress user ID.
	 * @param mixed $display_name Name shown to visitors; defaults to the user's display name.
	 * @param bool  $is_active    Whether the agent starts active.
	 * @return Agent|\WP_Error
	 */
	public function create( int $user_id, $display_name = '', bool $is_active = true ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'chatchi_user_not_found', __( 'This user does not exist.', 'chatchi' ), array( 'status' => 404 ) );
		}

		$raw  = is_scalar( $display_name ) && '' !== trim( (string) $display_name ) ? (string) $display_name : $user->display_name;
		$name = $this->clean_name( $raw );

		if ( $name instanceof \WP_Error ) {
			return $name;
		}

		try {
			if ( $this->agents->find_by_user_id( $user_id ) ) {
				return $this->already_agent();
			}

			return $this->agents->create( $user_id, $name, 0, $is_active );
		} catch ( \Throwable $e ) {
			// The unique user_id index also rejects a concurrent duplicate.
			return $this->is_agent( $user_id ) ? $this->already_agent() : $this->failure( $e );
		}
	}

	/**
	 * Changes an agent's name, picture or active state.
	 *
	 * @param int                  $id             Agent ID.
	 * @param array<string, mixed> $data           Any of display_name, avatar_id, is_active.
	 * @param int                  $acting_user_id User making the change.
	 * @return Agent|\WP_Error The updated agent.
	 */
	public function update( int $id, array $data, int $acting_user_id ) {
		$agent = $this->find( $id );

		if ( $agent instanceof \WP_Error ) {
			return $agent;
		}

		$fields = array();

		if ( array_key_exists( 'display_name', $data ) ) {
			$name = $this->clean_name( is_scalar( $data['display_name'] ) ? (string) $data['display_name'] : '' );

			if ( $name instanceof \WP_Error ) {
				return $name;
			}

			$fields['display_name'] = $name;
		}

		if ( array_key_exists( 'avatar_id', $data ) ) {
			$avatar_id = (int) $data['avatar_id'];

			if ( $avatar_id < 0 || ( $avatar_id > 0 && ! wp_attachment_is_image( $avatar_id ) ) ) {
				return new \WP_Error( 'chatchi_avatar_invalid', __( 'Choose an image from the media library.', 'chatchi' ), array( 'status' => 400 ) );
			}

			$fields['avatar_id'] = $avatar_id;
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$active = (bool) $data['is_active'];

			if ( ! $active && $agent->user_id === $acting_user_id ) {
				return new \WP_Error( 'chatchi_cannot_deactivate_self', __( 'You cannot deactivate your own agent account.', 'chatchi' ), array( 'status' => 400 ) );
			}

			$fields['is_active'] = $active;
		}

		if ( ! $fields ) {
			return $agent;
		}

		try {
			$this->agents->update( $agent->id, $fields );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( isset( $fields['avatar_id'] ) && $fields['avatar_id'] !== $agent->avatar_id ) {
			$this->avatars->discard( $agent->avatar_id );
		}

		return $this->find( $agent->id );
	}

	/**
	 * Uploads a picture and makes it the agent's avatar.
	 *
	 * @param int                       $id             Agent ID.
	 * @param array<string, mixed>|null $file           One entry of $_FILES.
	 * @param int                       $acting_user_id User making the change.
	 * @return Agent|\WP_Error The updated agent.
	 */
	public function upload_avatar( int $id, ?array $file, int $acting_user_id ) {
		$agent = $this->find( $id );

		if ( $agent instanceof \WP_Error ) {
			return $agent;
		}

		$attachment_id = $this->avatars->upload( $file );

		if ( $attachment_id instanceof \WP_Error ) {
			return $attachment_id;
		}

		$updated = $this->update( $agent->id, array( 'avatar_id' => $attachment_id ), $acting_user_id );

		if ( $updated instanceof \WP_Error ) {
			$this->avatars->discard( $attachment_id );
		}

		return $updated;
	}

	/**
	 * Finds WordPress users who are not agents yet.
	 *
	 * @param string $search Part of the login, email or display name.
	 * @return \WP_User[]|\WP_Error
	 */
	public function candidates( string $search = '' ) {
		try {
			$agent_user_ids = array_map( static fn( Agent $agent ): int => $agent->user_id, $this->agents->all() );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		$args = array(
			'number'  => self::CANDIDATE_LIMIT,
			'exclude' => $agent_user_ids,
			'orderby' => 'display_name',
		);

		$search = PlainText::line( $search );

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		return ( new \WP_User_Query( $args ) )->get_results();
	}

	/**
	 * Returns the users with the given IDs, keyed by ID, in one query.
	 *
	 * @param int[] $ids User IDs.
	 * @return array<int, \WP_User>
	 */
	public function users( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$users = array();

		foreach ( ( new \WP_User_Query( array( 'include' => $ids ) ) )->get_results() as $user ) {
			$users[ $user->ID ] = $user;
		}

		return $users;
	}

	/**
	 * @param int $id Agent ID.
	 * @return Agent|\WP_Error
	 */
	private function find( int $id ) {
		try {
			$agent = $this->agents->find( $id );
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}

		if ( ! $agent instanceof Agent ) {
			return new \WP_Error( 'chatchi_agent_not_found', __( 'Agent not found.', 'chatchi' ), array( 'status' => 404 ) );
		}

		return $agent;
	}

	/**
	 * @param string $raw Raw name.
	 * @return string|\WP_Error
	 */
	private function clean_name( string $raw ) {
		$name   = PlainText::line( $raw );
		$length = mb_strlen( $name );

		if ( $length < self::NAME_MIN_LENGTH || $length > self::NAME_MAX_LENGTH ) {
			return new \WP_Error(
				'chatchi_agent_name_invalid',
				/* translators: 1: minimum length, 2: maximum length. */
				sprintf( __( 'The name must be between %1$d and %2$d characters.', 'chatchi' ), self::NAME_MIN_LENGTH, self::NAME_MAX_LENGTH ),
				array( 'status' => 400 )
			);
		}

		return $name;
	}

	/**
	 * @param int $user_id WordPress user ID.
	 */
	private function is_agent( int $user_id ): bool {
		try {
			return null !== $this->agents->find_by_user_id( $user_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function already_agent(): \WP_Error {
		return new \WP_Error( 'chatchi_agent_exists', __( 'This user is already an agent.', 'chatchi' ), array( 'status' => 409 ) );
	}

	/**
	 * Logs an unexpected failure and returns a safe, generic error.
	 *
	 * @param \Throwable $error Failure.
	 */
	private function failure( \Throwable $error ): \WP_Error {
		return Failure::report( $error, 'Agent', 'chatchi_agent_unavailable' );
	}
}
