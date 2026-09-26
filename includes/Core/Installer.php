<?php
/**
 * Install and upgrade routine.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

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

	/**
	 * Seconds after which an abandoned lock is ignored.
	 */
	private const LOCK_TTL = 60;

	/**
	 * Runs the installer if the stored version is outdated.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === CHATCHI_VERSION ) {
			return;
		}

		$this->install();
	}

	/**
	 * Runs every install step for the current site.
	 *
	 * Steps must be idempotent: they can run again on every upgrade.
	 *
	 * @return bool False when another request is already installing.
	 */
	public function install(): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$previous_version = (string) get_option( self::VERSION_OPTION, '' );

			add_option( self::INSTALLED_AT_OPTION, time(), '', false );

			update_option( self::VERSION_OPTION, CHATCHI_VERSION, true );
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
