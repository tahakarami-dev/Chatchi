<?php
/**
 * Visitor identity resolution.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which visitor the current request belongs to.
 *
 * - Guests are identified by the token in their cookie.
 * - Logged-in users are identified by their WordPress account, so their
 *   conversations follow them across devices. A guest identity is linked to
 *   the account the first time that guest logs in.
 * - A guest cookie whose visitor is linked to an account is ignored while
 *   logged out, so on a shared computer the next guest cannot read the
 *   previous user's conversations.
 *
 * Identities are only created when a visitor actually starts chatting, never
 * on page views, so page caches and bots are unaffected.
 */
final class VisitorIdentity {

	/**
	 * Minimum seconds between last_seen_at updates and cookie refreshes.
	 */
	private const TOUCH_INTERVAL = 5 * MINUTE_IN_SECONDS;

	private VisitorRepository $visitors;

	private VisitorCookie $cookie;

	/**
	 * Per-request cache, keyed by WordPress user ID.
	 *
	 * @var array<int, Visitor|null>
	 */
	private array $resolved = array();

	/**
	 * @param VisitorRepository $visitors Visitor storage.
	 * @param VisitorCookie     $cookie   Visitor cookie.
	 */
	public function __construct( VisitorRepository $visitors, VisitorCookie $cookie ) {
		$this->visitors = $visitors;
		$this->cookie   = $cookie;
	}

	/**
	 * Returns the visitor making the current request, if one exists.
	 *
	 * @throws DatabaseException When the lookup fails.
	 */
	public function current(): ?Visitor {
		$user_id = get_current_user_id();

		if ( array_key_exists( $user_id, $this->resolved ) ) {
			return $this->resolved[ $user_id ];
		}

		$visitor = $user_id > 0 ? $this->for_user( $user_id ) : $this->for_guest();

		if ( $visitor ) {
			$this->keep_alive( $visitor, $user_id );
		}

		$this->resolved[ $user_id ] = $visitor;

		return $visitor;
	}

	/**
	 * Returns the current visitor, creating one if needed.
	 *
	 * @param array<string, string> $profile Sanitized profile for a new visitor.
	 *
	 * @throws DatabaseException When the visitor cannot be stored.
	 * @throws \Exception        When no secure random source is available.
	 */
	public function current_or_create( array $profile = array() ): Visitor {
		$visitor = $this->current();

		if ( $visitor ) {
			return $visitor;
		}

		$user_id = get_current_user_id();
		$token   = VisitorToken::generate();
		$visitor = $this->visitors->create( VisitorToken::hash( $token ), $profile, $user_id );

		// Logged-in users are identified by their account; only guests need the cookie.
		if ( 0 === $user_id ) {
			$this->cookie->write( $token );
		}

		$this->resolved[ $user_id ] = $visitor;

		return $visitor;
	}

	/**
	 * Drops the guest cookie and the per-request cache.
	 */
	public function forget(): void {
		$this->cookie->clear();
		$this->resolved = array();
	}

	/**
	 * Resolves a guest from the cookie token.
	 */
	private function for_guest(): ?Visitor {
		$token = $this->cookie->read();

		if ( null === $token ) {
			return null;
		}

		$visitor = $this->visitors->find_by_token_hash( VisitorToken::hash( $token ) );

		if ( ! $visitor || $visitor->user_id > 0 ) {
			return null;
		}

		return $visitor;
	}

	/**
	 * Resolves a logged-in user, adopting their guest identity if they have one.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function for_user( int $user_id ): ?Visitor {
		$visitor = $this->visitors->find_by_user_id( $user_id );

		if ( $visitor ) {
			return $visitor;
		}

		$guest = $this->for_guest();

		if ( ! $guest ) {
			return null;
		}

		$this->visitors->link_user( $guest->id, $user_id );
		$guest->user_id = $user_id;

		return $guest;
	}

	/**
	 * Updates last-seen time and extends the guest cookie (sliding expiration).
	 *
	 * Both are throttled together: tying the cookie refresh to last_seen_at
	 * alone would never renew it for visitors who return every day.
	 *
	 * @param Visitor $visitor Resolved visitor.
	 * @param int     $user_id Current WordPress user ID.
	 */
	private function keep_alive( Visitor $visitor, int $user_id ): void {
		$idle = time() - (int) strtotime( $visitor->last_seen_at . ' UTC' );

		if ( $idle < self::TOUCH_INTERVAL ) {
			return;
		}

		$this->visitors->touch( $visitor->id );

		if ( 0 === $user_id ) {
			$token = $this->cookie->read();

			if ( null !== $token ) {
				$this->cookie->write( $token );
			}
		}
	}
}
