<?php
/**
 * Resetting and deleting data from Settings → General.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Settings\DataReset;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /chatchi/v1/agent/settings/data        How much there is to delete.
 * POST /chatchi/v1/agent/settings/data/reset  scope (settings, conversations
 *                                            or all) and confirm: "reset".
 *
 * Administrators only. The panel asks the administrator to type a word
 * before it sends confirm, so a stray click cannot delete anything.
 */
final class DataResetController implements RouteProvider {

	private DataReset $reset;

	/**
	 * @param DataReset $reset Resetting and deleting data.
	 */
	public function __construct( DataReset $reset ) {
		$this->reset = $reset;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/settings/data',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/settings/data/reset',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
				'args'                => array(
					'scope'   => array(
						'type'     => 'string',
						'enum'     => DataReset::SCOPES,
						'required' => true,
					),
					'confirm' => array(
						'type'     => 'string',
						'enum'     => array( 'reset' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function summary(): \WP_REST_Response {
		return $this->respond( $this->reset->summary() );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset( \WP_REST_Request $request ) {
		$done = $this->reset->run( (string) $request['scope'] );

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		return $this->respond(
			array(
				'scope'   => (string) $request['scope'],
				'deleted' => $done,
				'summary' => $this->reset->summary(),
			)
		);
	}

	/**
	 * @param array<string, mixed> $data Response body.
	 */
	private function respond( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
