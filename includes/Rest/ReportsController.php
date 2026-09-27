<?php
/**
 * Reports endpoint.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Reports\ReportService;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * GET /chatchi/v1/agent/reports?range=7|30|90
 *
 * Administrators only (manage_options): the report shows every agent's
 * figures.
 */
final class ReportsController implements RouteProvider {

	private ReportService $reports;

	/**
	 * @param ReportService $reports Report builder.
	 */
	public function __construct( ReportService $reports ) {
		$this->reports = $reports;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/reports',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
				'args'                => array(
					'range' => array(
						'type'    => 'integer',
						'enum'    => ReportService::RANGES,
						'default' => 30,
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		try {
			$report = $this->reports->build( (int) $request['range'] );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'Reports', 'chatchi_unavailable' );
		}

		$response = new \WP_REST_Response( $report );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
