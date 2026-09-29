<?php
/**
 * Install and upgrade routine.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

use ChatChi\Database\Schema;
use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Brings the current site's stored data in line with the plugin version.
 *
 * Runs on activation and whenever the stored version differs from the code
 * version, which also covers updates that bypass the activation hook
 * (FTP uploads, deployments) and every site of a network activation.
 */
final class Installer {

	public const VERSION_OPTION      = 'chatchi_version';
	public const INSTALLED_AT_OPTION = 'chatchi_installed_at';
	public const LOCK_OPTION         = 'chatchi_install_lock';
	public const ERROR_OPTION        = 'chatchi_install_error';
	public const BACKOFF_TRANSIENT   = 'chatchi_install_backoff';

	/**
	 * Seconds after which an abandoned lock is ignored.
	 */
	private const LOCK_TTL = 60;

	/**
	 * Seconds to wait before retrying a failed automatic install.
	 */
	private const BACKOFF_TTL = 300;

	private Schema $schema;

	/**
	 * @param Schema $schema Table installer.
	 */
	public function __construct( Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Runs the installer if the stored version is outdated.
	 *
	 * Never throws: a failure is recorded, shown to admins, and retried
	 * after a back-off so a broken database cannot slow down every request.
	 */
	public function maybe_upgrade(): void {
		// The table version too: a new column may ship without a new plugin version.
		if ( get_option( self::VERSION_OPTION ) === CHATCHI_VERSION && get_option( Schema::VERSION_OPTION ) === Schema::VERSION ) {
			return;
		}

		if ( get_transient( self::BACKOFF_TRANSIENT ) ) {
			return;
		}

		try {
			$this->install();
		} catch ( \Throwable $e ) {
			// Already recorded by install(); the back-off schedules the retry.
			return;
		}
	}

	/**
	 * Runs every install step for the current site.
	 *
	 * Steps must be idempotent: they can run again on every upgrade.
	 *
	 * @return bool False when another request is already installing.
	 *
	 * @throws \Throwable When a step fails; the error is recorded first.
	 */
	public function install(): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$previous_version = (string) get_option( self::VERSION_OPTION, '' );

			$this->schema->install();

			add_option( self::INSTALLED_AT_OPTION, time(), '', false );

			// Created up front and autoloaded, so page views read the settings
			// without a database query even before anything was saved.
			add_option( Settings::OPTION, array(), '', true );

			update_option( self::VERSION_OPTION, CHATCHI_VERSION, true );

			delete_option( self::ERROR_OPTION );
			delete_transient( self::BACKOFF_TRANSIENT );
		} catch ( \Throwable $e ) {
			$this->record_failure( $e );
			throw $e;
		} finally {
			$this->release_lock();
		}

		/**
		 * Fires after ChatChi has been installed or upgraded on the current site.
		 *
		 * @param string $previous_version Version before this run; empty on a fresh install.
		 * @param string $current_version  Version now installed.
		 */
		do_action( 'chatchi_installed', $previous_version, CHATCHI_VERSION );

		return true;
	}

	/**
	 * Returns the message of the last failed install, or an empty string.
	 */
	public function last_error(): string {
		return (string) get_option( self::ERROR_OPTION, '' );
	}

	/**
	 * Stores the failure for the admin notice and starts the back-off.
	 *
	 * @param \Throwable $error Failure.
	 */
	private function record_failure( \Throwable $error ): void {
		update_option( self::ERROR_OPTION, $error->getMessage(), false );
		set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_TTL );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Install failures must reach the server log.
		error_log( '[ChatChi] Install failed: ' . $error->getMessage() );
	}

	/**
	 * Takes the install lock. add_option() is atomic because option_name is unique.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $locked_at > time() - self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, time(), '', false );
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
