<?php
/**
 * Plugin kernel: owns the service container and boots modules.
 *
 * @package ChatChi
 */

namespace ChatChi;

use ChatChi\Core\Container;
use ChatChi\Core\Contracts\Module;
use ChatChi\Core\I18n;
use ChatChi\Core\InstallNotice;
use ChatChi\Core\Installer;
use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Database\Schema;

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
		);

		foreach ( $repositories as $repository ) {
			$this->container->singleton(
				$repository,
				static function ( Container $c ) use ( $repository ) {
					return new $repository( $c->get( \wpdb::class ) );
				}
			);
		}

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
	 * Returns the ordered list of module IDs to boot.
	 *
	 * @return string[]
	 */
	private function modules(): array {
		$modules = array(
			I18n::class,
			InstallNotice::class,
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
