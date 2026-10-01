<?php
/**
 * Minimal service container.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds shared services from factories.
 *
 * Factories receive the container, so services can declare their
 * dependencies explicitly instead of reaching for globals.
 */
final class Container {

	/**
	 * Service factories keyed by ID.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved services keyed by ID.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * IDs currently being resolved, used to detect circular dependencies.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Registers a shared service. Re-registering replaces the previous one.
	 *
	 * @param string   $id      Service ID, usually a class name.
	 * @param callable $factory Receives the container and returns the service.
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Checks whether a service is registered.
	 *
	 * @param string $id Service ID.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}

	/**
	 * Returns a service, building it on first access.
	 *
	 * @param string $id Service ID.
	 * @return mixed
	 *
	 * @throws \OutOfBoundsException When the service is not registered.
	 * @throws \LogicException       When services depend on each other in a loop.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \OutOfBoundsException( sprintf( 'ChatChi service "%s" is not registered.', $id ) );
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \LogicException( sprintf( 'Circular dependency while resolving ChatChi service "%s".', $id ) );
		}

		$this->resolving[ $id ] = true;

		try {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		return $this->instances[ $id ];
	}
}
