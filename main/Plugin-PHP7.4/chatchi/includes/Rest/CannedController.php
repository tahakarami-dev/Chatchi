<?php
/**
 * Saved replies endpoints.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Canned\CannedResponses;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * /chatchi/v1/agent/canned…
 *
 * Every active agent can read the saved replies; only administrators
 * (manage_options) can add, change or delete them.
 */
final class CannedController implements RouteProvider {

	private CannedResponses $canned;

	private AgentRepository $agents;

	/**
	 * @param CannedResponses $canned Saved replies.
	 * @param AgentRepository $agents Agent storage, to allow active agents.
	 */
	public function __construct( CannedResponses $canned, AgentRepository $agents ) {
		$this->canned = $canned;
		$this->agents = $agents;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$fields = array(
			'title'    => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'shortcut' => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'body'     => array(
				'type'      => 'string',
				'maxLength' => 20000,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/canned',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permit_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( AdminPermission::class, 'check' ),
					'args'                => $fields,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/canned/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( AdminPermission::class, 'check' ),
					'args'                => $fields,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( AdminPermission::class, 'check' ),
				),
			)
		);
	}

	/**
	 * Administrators and active agents may read the saved replies.
	 *
	 * @return true|\WP_Error
	 */
	public function permit_read() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'chatchi_not_logged_in', __( 'Please log in to continue.', 'chatchi' ), array( 'status' => 401 ) );
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		try {
			$agent = $this->agents->find_by_user_id( get_current_user_id() );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'REST', 'chatchi_unavailable' );
		}

		return $agent && $agent->is_active ? true : AdminPermission::forbidden();
	}

	/**
	 * GET /agent/canned
	 */
	public function index(): \WP_REST_Response {
		return $this->respond( $this->canned->all() );
	}

	/**
	 * POST /agent/canned
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$item = $this->canned->create( $this->input( $request ) );

		return $item instanceof \WP_Error ? $item : $this->respond( $item, 201 );
	}

	/**
	 * POST/PATCH /agent/canned/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$item = $this->canned->update( (int) $request['id'], $this->input( $request ) );

		return $item instanceof \WP_Error ? $item : $this->respond( $item );
	}

	/**
	 * DELETE /agent/canned/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		$deleted = $this->canned->delete( (int) $request['id'] );

		return $deleted instanceof \WP_Error ? $deleted : $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * The fields that were sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$input = array();

		foreach ( array( 'title', 'shortcut', 'body' ) as $key ) {
			if ( $request->has_param( $key ) && null !== $request[ $key ] ) {
				$input[ $key ] = $request[ $key ];
			}
		}

		return $input;
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
