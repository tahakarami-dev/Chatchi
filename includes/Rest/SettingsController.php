<?php
/**
 * Settings endpoints.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Frontend\ColorPalette;
use ChatChi\Frontend\WidgetStrings;
use ChatChi\Security\ClientIp;
use ChatChi\Settings\ContentLookup;
use ChatChi\Settings\Settings;
use ChatChi\Settings\SettingsValidator;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * /chatchi/v1/agent/settings and /chatchi/v1/agent/content
 *
 * Site administrators only; see AdminPermission.
 */
final class SettingsController implements RouteProvider {

	private Settings $settings;

	private ContentLookup $content;

	private ClientIp $client_ip;

	private ColorPalette $palette;

	private WidgetStrings $strings;

	/**
	 * @param Settings      $settings  Plugin settings.
	 * @param ContentLookup $content   Pages, posts and products.
	 * @param ClientIp      $client_ip Visitor address, to show the admin what is detected.
	 * @param ColorPalette  $palette   Brand colors, for the effective color.
	 * @param WidgetStrings $strings   Widget text, for the default title and subtitle.
	 */
	public function __construct( Settings $settings, ContentLookup $content, ClientIp $client_ip, ColorPalette $palette, WidgetStrings $strings ) {
		$this->settings  = $settings;
		$this->content   = $content;
		$this->client_ip = $client_ip;
		$this->palette   = $palette;
		$this->strings   = $strings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( AdminPermission::class, 'check' ),
				),
				array(
					'methods'             => 'PATCH, POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( AdminPermission::class, 'check' ),
					'args'                => array(
						'welcome_enabled' => array(
							'type' => 'boolean',
						),
						'welcome_message' => array(
							'type'      => 'string',
							// Hard cap before cleaning; the real limit is checked by the validator.
							'maxLength' => 5000,
						),
						'display_mode'    => array(
							'type' => 'string',
							'enum' => SettingsValidator::DISPLAY_MODES,
						),
						'display_targets' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => SettingsValidator::DISPLAY_TARGETS,
							),
							'uniqueItems' => true,
						),
						'display_ids'     => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => SettingsValidator::MAX_DISPLAY_IDS,
						),
						'proxy_mode'      => array(
							'type' => 'string',
							'enum' => SettingsValidator::PROXY_MODES,
						),
						'proxy_header'    => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'primary_color'   => array(
							'type'      => 'string',
							'maxLength' => 20,
						),
						'widget_position' => array(
							'type' => 'string',
							'enum' => SettingsValidator::POSITIONS,
						),
						'widget_title'    => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'widget_subtitle' => array(
							'type'      => 'string',
							'maxLength' => 1000,
						),
						'trusted_proxies' => array(
							'type'     => 'array',
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
							'maxItems' => SettingsValidator::MAX_TRUSTED_PROXIES * 2,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/content',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
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
	 * GET /agent/settings
	 */
	public function show(): \WP_REST_Response {
		return $this->respond( $this->payload( $this->settings->all() ) );
	}

	/**
	 * PATCH /agent/settings: saves the settings that were sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$changes = array();

		foreach ( array( 'welcome_enabled', 'welcome_message', 'display_mode', 'display_targets', 'display_ids', 'proxy_mode', 'proxy_header', 'trusted_proxies', 'primary_color', 'widget_position', 'widget_title', 'widget_subtitle' ) as $key ) {
			if ( $request->has_param( $key ) && null !== $request[ $key ] ) {
				$changes[ $key ] = $request[ $key ];
			}
		}

		$settings = $this->settings->update( $changes );

		return $settings instanceof \WP_Error ? $settings : $this->respond( $this->payload( $settings ) );
	}

	/**
	 * GET /agent/content: published pages, posts and products by title.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( array_map( array( $this, 'item' ), $this->content->search( (string) $request['search'] ) ) );
	}

	/**
	 * Builds the settings response.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function payload( array $settings ): array {
		$targets = array( 'home', 'pages', 'posts' );

		if ( $this->content->has_products() ) {
			$targets[] = 'products';
		}

		return array(
			'settings'  => $settings,
			'defaults'  => array(
				'welcome_message' => $this->settings->default_welcome_message(),
				'primary_color'   => ColorPalette::DEFAULT_PRIMARY,
				'widget_title'    => $this->strings->all()['title'],
				'widget_subtitle' => $this->strings->all()['subtitle'],
			),
			'limits'    => array(
				'welcome_message' => SettingsValidator::WELCOME_MAX_LENGTH,
				'display_ids'     => SettingsValidator::MAX_DISPLAY_IDS,
				'widget_title'    => SettingsValidator::TITLE_MAX_LENGTH,
				'widget_subtitle' => SettingsValidator::SUBTITLE_MAX_LENGTH,
			),
			// What the widget actually uses, after filters from other code.
			'effective' => array(
				'primary_color' => $this->palette->primary(),
			),
			// The admin's own address as the plugin sees it with these settings.
			'your_ip'   => $this->client_ip->get(),
			'targets'   => $targets,
			// Chosen items that still exist, with titles for display.
			'items'     => array_map( array( $this, 'item' ), $this->content->find( (array) $settings['display_ids'] ) ),
		);
	}

	/**
	 * @param \WP_Post $post Page, post or product.
	 * @return array<string, mixed>
	 */
	private function item( \WP_Post $post ): array {
		$title = PlainText::line( $post->post_title );

		return array(
			'id'         => $post->ID,
			'title'      => '' !== $title ? $title : __( '(no title)', 'chatchi' ),
			'type'       => $post->post_type,
			'type_label' => $this->content->type_label( $post ),
			'url'        => esc_url_raw( (string) get_permalink( $post ) ),
		);
	}

	/**
	 * @param mixed $data Response body.
	 */
	private function respond( $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
