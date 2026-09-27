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
use ChatChi\Settings\ProactiveRules;
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
					'args'                => $this->settings_args(),
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
	 * Returns the REST arguments of the settings that can be saved.
	 *
	 * Types and hard size caps only; the validator checks the real rules
	 * and reports errors per field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function settings_args(): array {
		$text = static function ( int $max ): array {
			return array(
				'type'      => 'string',
				'maxLength' => $max,
			);
		};
		$enum = static function ( array $values ): array {
			return array(
				'type' => 'string',
				'enum' => $values,
			);
		};
		$flag = array( 'type' => 'boolean' );
		$args = array(
			'widget_enabled'           => $flag,
			'widget_sound'             => $flag,
			'delete_data_on_uninstall' => $flag,
			'primary_color'            => $text( 20 ),
			'widget_position'          => $enum( SettingsValidator::POSITIONS ),
			'widget_title'             => $text( 1000 ),
			'widget_subtitle'          => $text( 1000 ),
			'form_name'                => $enum( SettingsValidator::FIELD_MODES ),
			'form_email'               => $enum( SettingsValidator::FIELD_MODES ),
			'form_phone'               => $enum( SettingsValidator::FIELD_MODES ),
			'form_intro'               => $text( 2000 ),
			'welcome_enabled'          => $flag,
			// Hard cap before cleaning; the real limit is checked by the validator.
			'welcome_message'          => $text( 5000 ),
			'display_mode'             => $enum( SettingsValidator::DISPLAY_MODES ),
			'display_targets'          => array(
				'type'        => 'array',
				'items'       => $enum( SettingsValidator::DISPLAY_TARGETS ),
				'uniqueItems' => true,
			),
			'display_ids'              => array(
				'type'     => 'array',
				'items'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems' => SettingsValidator::MAX_DISPLAY_IDS,
			),
			'spam_protection'          => $flag,
			'proxy_mode'               => $enum( SettingsValidator::PROXY_MODES ),
			'proxy_header'             => $text( 64 ),
			'trusted_proxies'          => array(
				'type'     => 'array',
				'items'    => $text( 64 ),
				'maxItems' => SettingsValidator::MAX_TRUSTED_PROXIES * 2,
			),
			'hours_enabled'            => $flag,
			// Days are checked by the validator (Schedule::clean()).
			'hours'                    => array( 'type' => 'object' ),
			'offline_mode'             => $enum( SettingsValidator::OFFLINE_MODES ),
			'offline_no_agents'        => $flag,
			'offline_message'          => $text( 3000 ),
			'notify_email_enabled'     => $flag,
			'notify_email'             => $text( 200 ),
			'attachments_enabled'      => $flag,
			'telegram_enabled'         => $flag,
			'telegram_token'           => $text( 200 ),
			'telegram_chat_id'         => $text( 100 ),
			'telegram_api_url'         => $text( 300 ),
			'bale_enabled'             => $flag,
			'bale_token'               => $text( 200 ),
			'bale_chat_id'             => $text( 100 ),
			'bot_notify'               => $enum( SettingsValidator::BOT_NOTIFY ),
			'transcript_enabled'       => $flag,
			'rating_enabled'           => $flag,
			// Rules are checked by the validator (ProactiveRules::clean()).
			'proactive'                => array(
				'type'     => 'array',
				'maxItems' => 50,
			),
		);

		// Numbers arrive as JSON numbers or numeric strings; ranges are the validator's job.
		foreach ( array_keys( SettingsValidator::NUMBERS ) as $key ) {
			$args[ $key ] = array( 'type' => array( 'integer', 'string' ) );
		}

		return $args;
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

		foreach ( array_keys( $this->settings_args() ) as $key ) {
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
				'form_intro'      => $this->strings->all()['formIntro'],
				'offline_message' => $this->settings->default_offline_message(),
				'notify_email'    => (string) get_option( 'admin_email' ),
			),
			'limits'    => array(
				'welcome_message' => SettingsValidator::WELCOME_MAX_LENGTH,
				'display_ids'     => SettingsValidator::MAX_DISPLAY_IDS,
				'widget_title'    => SettingsValidator::TITLE_MAX_LENGTH,
				'widget_subtitle' => SettingsValidator::SUBTITLE_MAX_LENGTH,
				'form_intro'      => SettingsValidator::FORM_INTRO_MAX_LENGTH,
				'offline_message' => SettingsValidator::OFFLINE_MESSAGE_MAX_LENGTH,
				'proactive'       => ProactiveRules::MAX_RULES,
				'proactive_text'  => ProactiveRules::MESSAGE_MAX_LENGTH,
				// The server's own upload limit caps attachment_max_mb.
				'upload_max_mb'   => max( 1, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) ),
			),
			// Business hours are in the site's time zone (Settings > General).
			'timezone'  => wp_timezone_string(),
			// Allowed ranges of the number settings: key => [min, max].
			'ranges'    => array_map(
				static function ( array $range ): array {
					return array( $range[0], $range[1] );
				},
				SettingsValidator::NUMBERS
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
