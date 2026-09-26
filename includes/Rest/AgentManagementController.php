<?php
/**
 * Agent management endpoints.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Agents\AgentService;
use ChatChi\Models\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * /chatchi/v1/agent/agents… and /chatchi/v1/agent/users
 *
 * Site administrators only; see AdminPermission.
 */
final class AgentManagementController implements RouteProvider {

	private AgentService $agents;

	private Presenter $presenter;

	/**
	 * @param AgentService $agents    Agent management.
	 * @param Presenter    $presenter Response shapes.
	 */
	public function __construct( AgentService $agents, Presenter $presenter ) {
		$this->agents    = $agents;
		$this->presenter = $presenter;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/agents',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permit' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permit' ),
					'args'                => array(
						'user_id'      => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
						'display_name' => array(
							'type'      => 'string',
							'maxLength' => 200,
							'default'   => '',
						),
						'is_active'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/agents/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH, POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'permit' ),
				'args'                => array(
					'id'           => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'display_name' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'avatar_id'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'is_active'    => array(
						'type' => 'boolean',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/agents/(?P<id>\d+)/avatar',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_avatar' ),
				'permission_callback' => array( $this, 'permit_upload' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/users',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => array( $this, 'permit' ),
				'args'                => array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: site administrators only.
	 *
	 * @return true|\WP_Error
	 */
	public function permit() {
		return AdminPermission::check();
	}

	/**
	 * Permission callback for uploads: administrators who may upload files.
	 *
	 * @return true|\WP_Error
	 */
	public function permit_upload() {
		$allowed = $this->permit();

		if ( true === $allowed && ! current_user_can( 'upload_files' ) ) {
			return AdminPermission::forbidden();
		}

		return $allowed;
	}

	/**
	 * GET /agent/agents
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index() {
		$rows = $this->agents->all();

		if ( $rows instanceof \WP_Error ) {
			return $rows;
		}

		$me = get_current_user_id();

		return $this->respond(
			array_map(
				function ( array $row ) use ( $me ): array {
					return $this->presenter->agent_for_admin( $row['agent'], $row['user'], $me );
				},
				$rows
			)
		);
	}

	/**
	 * POST /agent/agents
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$agent = $this->agents->create( (int) $request['user_id'], $request['display_name'], (bool) $request['is_active'] );

		return $agent instanceof \WP_Error ? $agent : $this->present( $agent, 201 );
	}

	/**
	 * PATCH /agent/agents/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$data = array();

		foreach ( array( 'display_name', 'avatar_id', 'is_active' ) as $field ) {
			if ( $request->has_param( $field ) && null !== $request[ $field ] ) {
				$data[ $field ] = $request[ $field ];
			}
		}

		$agent = $this->agents->update( (int) $request['id'], $data, get_current_user_id() );

		return $agent instanceof \WP_Error ? $agent : $this->present( $agent );
	}

	/**
	 * POST /agent/agents/{id}/avatar (multipart, field "file")
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_avatar( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$agent = $this->agents->upload_avatar( (int) $request['id'], isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null, get_current_user_id() );

		return $agent instanceof \WP_Error ? $agent : $this->present( $agent );
	}

	/**
	 * GET /agent/users: WordPress users who can be made agents.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function candidates( \WP_REST_Request $request ) {
		$users = $this->agents->candidates( (string) $request['search'] );

		if ( $users instanceof \WP_Error ) {
			return $users;
		}

		return $this->respond( array_map( array( $this->presenter, 'user_candidate' ), $users ) );
	}

	/**
	 * @param Agent $agent  Agent.
	 * @param int   $status HTTP status.
	 */
	private function present( Agent $agent, int $status = 200 ): \WP_REST_Response {
		$users = $this->agents->users( array( $agent->user_id ) );

		return $this->respond( $this->presenter->agent_for_admin( $agent, $users[ $agent->user_id ] ?? null, get_current_user_id() ), $status );
	}

	/**
	 * @param mixed $data   Response body.
	 * @param int   $status HTTP status.
	 */
	private function respond( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
