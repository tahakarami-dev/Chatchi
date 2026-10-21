<?php
/**
 * PSR-4 autoloader for the ChatChi namespace.
 *
 * @package ChatChi
 */

namespace ChatChi;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `ChatChi\Foo\Bar` to `includes/Foo/Bar.php`.
 */
final class Autoloader {

	private const NAMESPACE_PREFIX = 'ChatChi\\';

	/**
	 * Registers the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Loads the file for a ChatChi class, interface or trait.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );

		// Reject anything that could escape the includes directory.
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$file = CHATCHI_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
