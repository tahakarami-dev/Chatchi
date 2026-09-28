<?php
/**
 * Plugin kernel: owns the service container and boots modules.
 *
 * @package ChatChi
 */

namespace ChatChi;

use ChatChi\Admin\AdminModule;
use ChatChi\Agents\AgentService;
use ChatChi\Agents\AvatarUploader;
use ChatChi\Ai\AiClient;
use ChatChi\Ai\Assistant;
use ChatChi\Ai\Knowledge;
use ChatChi\Attachments\AttachmentService;
use ChatChi\Attachments\FileStore;
use ChatChi\Availability\Availability;
use ChatChi\Availability\OfflineNotifier;
use ChatChi\Commerce\CustomerContext;
use ChatChi\Canned\CannedResponses;
use ChatChi\Admin\PanelAccess;
use ChatChi\Admin\PanelPage;
use ChatChi\Admin\PanelStrings;
use ChatChi\Conversations\ConversationPolicy;
use ChatChi\Conversations\ConversationService;
use ChatChi\Conversations\RatingService;
use ChatChi\Core\Container;
use ChatChi\Core\Contracts\Module;
use ChatChi\Core\I18n;
use ChatChi\Core\InstallNotice;
use ChatChi\Core\Installer;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\AttachmentRepository;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\RateLimitRepository;
use ChatChi\Database\Repositories\TagRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Database\Schema;
use ChatChi\Frontend\ColorPalette;
use ChatChi\Frontend\DisplayRules;
use ChatChi\Frontend\FontFace;
use ChatChi\Frontend\Shortcode;
use ChatChi\Frontend\WidgetModule;
use ChatChi\Frontend\WidgetStrings;
use ChatChi\Messages\MessageBody;
use ChatChi\Messages\MessageService;
use ChatChi\Reports\ReportService;
use ChatChi\Rest\AgentController;
use ChatChi\Rest\AgentManagementController;
use ChatChi\Rest\AiTestController;
use ChatChi\Rest\BotTestController;
use ChatChi\Notifications\BotNotifier;
use ChatChi\Notifications\SmsGateway;
use ChatChi\Notifications\SmsNotifier;
use ChatChi\Notifications\Transcript;
use ChatChi\Rest\CannedController;
use ChatChi\Rest\Presenter;
use ChatChi\Rest\RequestGuard;
use ChatChi\Rest\ReportsController;
use ChatChi\Rest\RestModule;
use ChatChi\Rest\SettingsController;
use ChatChi\Rest\SmsTestController;
use ChatChi\Rest\WidgetController;
use ChatChi\Security\ClientIp;
use ChatChi\Security\RateLimiter;
use ChatChi\Security\SecurityModule;
use ChatChi\Security\SpamGuard;
use ChatChi\Settings\ContentLookup;
use ChatChi\Settings\Settings;
use ChatChi\Settings\SettingsValidator;
use ChatChi\Visitors\ProfileFields;
use ChatChi\Visitors\ProfileValidator;
use ChatChi\Visitors\VisitorCookie;
use ChatChi\Visitors\VisitorIdentity;
use ChatChi\Visitors\VisitorService;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point for everything ChatChi does at runtime.
 *
 * Feature code lives in modules; this class only wires them together.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Returns the service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Returns whether boot() has already run.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Runs pending upgrades and registers the hooks of every module.
	 *
	 * Safe to call more than once; only the first call has an effect.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->container->get( Installer::class )->maybe_upgrade();

		/**
		 * Fires before modules are registered, so extensions can add services.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'chatchi_register_services', $this->container );

		foreach ( $this->modules() as $module_id ) {
			$this->register_module( $module_id );
		}

		/**
		 * Fires after all ChatChi modules have registered their hooks.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'chatchi_loaded', $this );
	}

	/**
	 * Registers the core services shared across modules.
	 */
	private function register_services(): void {
		$this->container->singleton(
			\wpdb::class,
			static function (): \wpdb {
				global $wpdb;
				return $wpdb;
			}
		);

		$this->container->singleton(
			Schema::class,
			static function ( Container $c ): Schema {
				return new Schema( $c->get( \wpdb::class ) );
			}
		);

		$this->container->singleton(
			Installer::class,
			static function ( Container $c ): Installer {
				return new Installer( $c->get( Schema::class ) );
			}
		);

		$repositories = array(
			VisitorRepository::class,
			AgentRepository::class,
			ConversationRepository::class,
			MessageRepository::class,
			RateLimitRepository::class,
			AttachmentRepository::class,
			TagRepository::class,
		);

		foreach ( $repositories as $repository ) {
			$this->container->singleton(
				$repository,
				static function ( Container $c ) use ( $repository ) {
					return new $repository( $c->get( \wpdb::class ) );
				}
			);
		}

		$this->register_settings_services();
		$this->register_security_services();
		$this->register_visitor_services();
		$this->register_conversation_services();
		$this->register_message_services();
		$this->register_rest_services();
		$this->register_frontend_services();
		$this->register_availability_services();
		$this->register_admin_services();

		$this->container->singleton(
			I18n::class,
			static function (): I18n {
				return new I18n();
			}
		);

		$this->container->singleton(
			InstallNotice::class,
			static function ( Container $c ): InstallNotice {
				return new InstallNotice( $c->get( Installer::class ) );
			}
		);
	}

	/**
	 * Registers the settings store.
	 */
	private function register_settings_services(): void {
		$this->container->singleton(
			ContentLookup::class,
			static function (): ContentLookup {
				return new ContentLookup();
			}
		);

		$this->container->singleton(
			SettingsValidator::class,
			static function ( Container $c ): SettingsValidator {
				return new SettingsValidator( $c->get( ContentLookup::class ) );
			}
		);

		$this->container->singleton(
			Settings::class,
			static function ( Container $c ): Settings {
				return new Settings( $c->get( SettingsValidator::class ) );
			}
		);
	}

	/**
	 * Registers rate limiting and bot protection.
	 */
	private function register_security_services(): void {
		$this->container->singleton(
			ClientIp::class,
			static function ( Container $c ): ClientIp {
				return new ClientIp( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			RateLimiter::class,
			static function ( Container $c ): RateLimiter {
				return new RateLimiter( $c->get( RateLimitRepository::class ), $c->get( ClientIp::class ), $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			SpamGuard::class,
			static function ( Container $c ): SpamGuard {
				return new SpamGuard( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			SecurityModule::class,
			static function ( Container $c ): SecurityModule {
				return new SecurityModule( $c->get( RateLimiter::class ) );
			}
		);
	}

	/**
	 * Registers visitor identity and profile services.
	 */
	private function register_visitor_services(): void {
		$this->container->singleton(
			VisitorCookie::class,
			static function ( Container $c ): VisitorCookie {
				return new VisitorCookie( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			VisitorIdentity::class,
			static function ( Container $c ): VisitorIdentity {
				return new VisitorIdentity( $c->get( VisitorRepository::class ), $c->get( VisitorCookie::class ) );
			}
		);

		$this->container->singleton(
			ProfileFields::class,
			static function ( Container $c ): ProfileFields {
				return new ProfileFields( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			ProfileValidator::class,
			static function (): ProfileValidator {
				return new ProfileValidator();
			}
		);

		$this->container->singleton(
			VisitorService::class,
			static function ( Container $c ): VisitorService {
				return new VisitorService(
					$c->get( VisitorIdentity::class ),
					$c->get( VisitorRepository::class ),
					$c->get( ProfileFields::class ),
					$c->get( ProfileValidator::class )
				);
			}
		);
	}

	/**
	 * Registers conversation access rules and use cases.
	 */
	private function register_conversation_services(): void {
		$this->container->singleton(
			ConversationPolicy::class,
			static function (): ConversationPolicy {
				return new ConversationPolicy();
			}
		);

		$this->container->singleton(
			ConversationService::class,
			static function ( Container $c ): ConversationService {
				return new ConversationService(
					$c->get( ConversationRepository::class ),
					$c->get( VisitorRepository::class ),
					$c->get( AgentRepository::class ),
					$c->get( MessageRepository::class ),
					$c->get( ConversationPolicy::class ),
					$c->get( TagRepository::class )
				);
			}
		);
	}

	/**
	 * Registers message rules and use cases.
	 */
	private function register_message_services(): void {
		$this->container->singleton(
			MessageBody::class,
			static function ( Container $c ): MessageBody {
				return new MessageBody( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			MessageService::class,
			static function ( Container $c ): MessageService {
				return new MessageService(
					$c->get( ConversationService::class ),
					$c->get( ConversationRepository::class ),
					$c->get( MessageRepository::class ),
					$c->get( AgentRepository::class ),
					$c->get( MessageBody::class )
				);
			}
		);

		$this->container->singleton(
			RatingService::class,
			static function ( Container $c ): RatingService {
				return new RatingService(
					$c->get( Settings::class ),
					$c->get( ConversationService::class ),
					$c->get( ConversationRepository::class ),
					$c->get( MessageService::class )
				);
			}
		);
	}

	/**
	 * Registers the REST API controllers.
	 */
	private function register_rest_services(): void {
		$this->container->singleton(
			Presenter::class,
			static function ( Container $c ): Presenter {
				return new Presenter( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			RequestGuard::class,
			static function (): RequestGuard {
				return new RequestGuard();
			}
		);

		$this->container->singleton(
			WidgetController::class,
			static function ( Container $c ): WidgetController {
				return new WidgetController(
					$c->get( ConversationService::class ),
					$c->get( MessageService::class ),
					$c->get( Presenter::class ),
					$c->get( VisitorService::class ),
					$c->get( AgentRepository::class ),
					$c->get( MessageBody::class ),
					$c->get( RequestGuard::class ),
					$c->get( RateLimiter::class ),
					$c->get( SpamGuard::class ),
					$c->get( Availability::class ),
					$c->get( AttachmentService::class ),
					$c->get( CustomerContext::class ),
					$c->get( RatingService::class ),
					$c->get( Assistant::class )
				);
			}
		);

		$this->container->singleton(
			AgentController::class,
			static function ( Container $c ): AgentController {
				return new AgentController(
					$c->get( ConversationService::class ),
					$c->get( MessageService::class ),
					$c->get( Presenter::class ),
					$c->get( AgentRepository::class ),
					$c->get( AttachmentService::class ),
					$c->get( Transcript::class ),
					$c->get( CustomerContext::class ),
					$c->get( VisitorRepository::class ),
					$c->get( Assistant::class )
				);
			}
		);

		$this->container->singleton(
			AvatarUploader::class,
			static function (): AvatarUploader {
				return new AvatarUploader();
			}
		);

		$this->container->singleton(
			AgentService::class,
			static function ( Container $c ): AgentService {
				return new AgentService( $c->get( AgentRepository::class ), $c->get( AvatarUploader::class ) );
			}
		);

		$this->container->singleton(
			FileStore::class,
			static function (): FileStore {
				return new FileStore();
			}
		);

		$this->container->singleton(
			AttachmentService::class,
			static function ( Container $c ): AttachmentService {
				return new AttachmentService(
					$c->get( ConversationService::class ),
					$c->get( MessageService::class ),
					$c->get( MessageRepository::class ),
					$c->get( AttachmentRepository::class ),
					$c->get( FileStore::class ),
					$c->get( Settings::class )
				);
			}
		);

		$this->container->singleton(
			CannedResponses::class,
			static function (): CannedResponses {
				return new CannedResponses();
			}
		);

		$this->container->singleton(
			CannedController::class,
			static function ( Container $c ): CannedController {
				return new CannedController( $c->get( CannedResponses::class ), $c->get( AgentRepository::class ) );
			}
		);

		$this->container->singleton(
			AgentManagementController::class,
			static function ( Container $c ): AgentManagementController {
				return new AgentManagementController( $c->get( AgentService::class ), $c->get( Presenter::class ) );
			}
		);

		$this->container->singleton(
			SettingsController::class,
			static function ( Container $c ): SettingsController {
				return new SettingsController(
					$c->get( Settings::class ),
					$c->get( ContentLookup::class ),
					$c->get( ClientIp::class ),
					$c->get( ColorPalette::class ),
					$c->get( WidgetStrings::class )
				);
			}
		);

		$this->container->singleton(
			ReportService::class,
			static function ( Container $c ): ReportService {
				return new ReportService( $c->get( \wpdb::class ), $c->get( AgentRepository::class ), $c->get( VisitorRepository::class ) );
			}
		);

		$this->container->singleton(
			ReportsController::class,
			static function ( Container $c ): ReportsController {
				return new ReportsController( $c->get( ReportService::class ) );
			}
		);

		$this->container->singleton(
			RestModule::class,
			static function ( Container $c ): RestModule {
				return new RestModule(
					$c->get( WidgetController::class ),
					$c->get( AgentController::class ),
					$c->get( AgentManagementController::class ),
					$c->get( SettingsController::class ),
					$c->get( CannedController::class ),
					$c->get( BotTestController::class ),
					$c->get( ReportsController::class ),
					$c->get( SmsTestController::class ),
					$c->get( AiTestController::class )
				);
			}
		);
	}

	/**
	 * Registers the front-end chat widget.
	 */
	private function register_frontend_services(): void {
		$this->container->singleton(
			ColorPalette::class,
			static function ( Container $c ): ColorPalette {
				return new ColorPalette( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			WidgetStrings::class,
			static function (): WidgetStrings {
				return new WidgetStrings();
			}
		);

		$this->container->singleton(
			FontFace::class,
			static function (): FontFace {
				return new FontFace();
			}
		);

		$this->container->singleton(
			DisplayRules::class,
			static function ( Container $c ): DisplayRules {
				return new DisplayRules( $c->get( Settings::class ) );
			}
		);

		$this->container->singleton(
			WidgetModule::class,
			static function ( Container $c ): WidgetModule {
				return new WidgetModule(
					$c->get( ColorPalette::class ),
					$c->get( WidgetStrings::class ),
					$c->get( FontFace::class ),
					$c->get( Settings::class ),
					$c->get( DisplayRules::class )
				);
			}
		);

		$this->container->singleton(
			Shortcode::class,
			static function ( Container $c ): Shortcode {
				return new Shortcode( $c->get( WidgetModule::class ) );
			}
		);
	}

	/**
	 * Registers business hours, agent presence and offline notifications.
	 */
	private function register_availability_services(): void {
		$this->container->singleton(
			Availability::class,
			static function ( Container $c ): Availability {
				return new Availability( $c->get( Settings::class ), $c->get( AgentRepository::class ) );
			}
		);

		$this->container->singleton(
			BotNotifier::class,
			static function ( Container $c ): BotNotifier {
				return new BotNotifier(
					$c->get( Settings::class ),
					$c->get( Availability::class ),
					$c->get( MessageRepository::class ),
					$c->get( VisitorRepository::class )
				);
			}
		);

		$this->container->singleton(
			CustomerContext::class,
			static function ( Container $c ): CustomerContext {
				return new CustomerContext( $c->get( VisitorRepository::class ) );
			}
		);

		$this->container->singleton(
			Transcript::class,
			static function ( Container $c ): Transcript {
				return new Transcript(
					$c->get( Settings::class ),
					$c->get( ConversationService::class ),
					$c->get( MessageRepository::class ),
					$c->get( VisitorRepository::class ),
					$c->get( AgentRepository::class ),
					$c->get( ColorPalette::class )
				);
			}
		);

		$this->container->singleton(
			AiClient::class,
			static function (): AiClient {
				return new AiClient();
			}
		);

		$this->container->singleton(
			Knowledge::class,
			static function ( Container $c ): Knowledge {
				return new Knowledge( $c->get( Settings::class ), $c->get( ContentLookup::class ), $c->get( CustomerContext::class ), $c->get( Availability::class ) );
			}
		);

		$this->container->singleton(
			Assistant::class,
			static function ( Container $c ): Assistant {
				return new Assistant(
					$c->get( Settings::class ),
					$c->get( ConversationService::class ),
					$c->get( ConversationRepository::class ),
					$c->get( MessageRepository::class ),
					$c->get( VisitorRepository::class ),
					$c->get( MessageService::class ),
					$c->get( Availability::class ),
					$c->get( AiClient::class ),
					$c->get( Knowledge::class )
				);
			}
		);

		$this->container->singleton(
			AiTestController::class,
			static function ( Container $c ): AiTestController {
				return new AiTestController( $c->get( AiClient::class ), $c->get( SettingsValidator::class ) );
			}
		);

		$this->container->singleton(
			SmsGateway::class,
			static function (): SmsGateway {
				return new SmsGateway();
			}
		);

		$this->container->singleton(
			SmsNotifier::class,
			static function ( Container $c ): SmsNotifier {
				return new SmsNotifier(
					$c->get( Settings::class ),
					$c->get( ConversationRepository::class ),
					$c->get( VisitorRepository::class ),
					$c->get( MessageRepository::class ),
					$c->get( MessageService::class ),
					$c->get( SmsGateway::class ),
					$c->get( ProfileValidator::class )
				);
			}
		);

		$this->container->singleton(
			SmsTestController::class,
			static function ( Container $c ): SmsTestController {
				return new SmsTestController( $c->get( SmsGateway::class ), $c->get( SettingsValidator::class ), $c->get( ProfileValidator::class ) );
			}
		);

		$this->container->singleton(
			BotTestController::class,
			static function ( Container $c ): BotTestController {
				return new BotTestController( $c->get( BotNotifier::class ), $c->get( SettingsValidator::class ) );
			}
		);

		$this->container->singleton(
			OfflineNotifier::class,
			static function ( Container $c ): OfflineNotifier {
				return new OfflineNotifier(
					$c->get( Availability::class ),
					$c->get( Settings::class ),
					$c->get( MessageRepository::class ),
					$c->get( VisitorRepository::class )
				);
			}
		);
	}

	/**
	 * Registers the standalone agent panel.
	 */
	private function register_admin_services(): void {
		$this->container->singleton(
			PanelAccess::class,
			static function ( Container $c ): PanelAccess {
				return new PanelAccess( $c->get( AgentRepository::class ) );
			}
		);

		$this->container->singleton(
			PanelStrings::class,
			static function (): PanelStrings {
				return new PanelStrings();
			}
		);

		$this->container->singleton(
			PanelPage::class,
			static function ( Container $c ): PanelPage {
				return new PanelPage(
					$c->get( ColorPalette::class ),
					$c->get( FontFace::class ),
					$c->get( PanelStrings::class ),
					$c->get( AvatarUploader::class )->max_bytes(),
					$c->get( Settings::class )
				);
			}
		);

		$this->container->singleton(
			AdminModule::class,
			static function ( Container $c ): AdminModule {
				return new AdminModule( $c->get( PanelAccess::class ), $c->get( PanelPage::class ), $c->get( ConversationRepository::class ) );
			}
		);
	}

	/**
	 * Returns the ordered list of module IDs to boot.
	 *
	 * @return string[]
	 */
	private function modules(): array {
		$modules = array(
			I18n::class,
			InstallNotice::class,
			SecurityModule::class,
			RestModule::class,
			OfflineNotifier::class,
			BotNotifier::class,
			SmsNotifier::class,
			Transcript::class,
			WidgetModule::class,
			Shortcode::class,
			AdminModule::class,
		);

		/**
		 * Filters the modules ChatChi boots.
		 *
		 * Each entry is a container service ID or the class name of a
		 * Module with a no-argument constructor.
		 *
		 * @param string[]  $modules   Module IDs.
		 * @param Container $container Service container.
		 */
		$modules = apply_filters( 'chatchi_modules', $modules, $this->container );

		return array_values( array_unique( array_filter( (array) $modules, 'is_string' ) ) );
	}

	/**
	 * Resolves a module and lets it register its hooks.
	 *
	 * @param string $module_id Container service ID or Module class name.
	 */
	private function register_module( string $module_id ): void {
		if ( ! $this->container->has( $module_id ) && class_exists( $module_id ) ) {
			$this->container->singleton(
				$module_id,
				static function () use ( $module_id ) {
					return new $module_id();
				}
			);
		}

		if ( ! $this->container->has( $module_id ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'ChatChi module "%s" could not be found.', $module_id ) ),
				'0.1.0'
			);
			return;
		}

		$module = $this->container->get( $module_id );

		if ( ! $module instanceof Module ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'ChatChi module "%s" must implement %s.', $module_id, Module::class ) ),
				'0.1.0'
			);
			return;
		}

		$module->register();
	}

	private function __clone() {}

	/**
	 * Prevents unserializing the singleton.
	 *
	 * @throws \LogicException Always.
	 */
	public function __wakeup() {
		throw new \LogicException( 'ChatChi\Plugin cannot be unserialized.' );
	}
}
