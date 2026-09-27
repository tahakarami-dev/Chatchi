<?php
/**
 * Visitor model.
 *
 * @package ChatChi
 */

namespace ChatChi\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A person chatting from the site front end.
 */
final class Visitor {

	public int $id = 0;

	/**
	 * Hash of the visitor's cookie token. Never exposed outside the server.
	 */
	public string $token_hash = '';

	/**
	 * Linked WordPress user, 0 for guests.
	 */
	public int $user_id = 0;

	public string $name = '';

	public string $email = '';

	public string $phone = '';

	public string $created_at = '';

	public string $last_seen_at = '';

	/**
	 * Page the visitor is on, as last reported by the widget.
	 */
	public string $current_url = '';

	public string $current_title = '';

	/**
	 * WooCommerce cart when last reported, decoded; null when unknown.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $cart = null;

	/**
	 * When the page and cart were last reported (UTC), or null.
	 */
	public ?string $context_at = null;

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$visitor               = new self();
		$visitor->id           = (int) $row['id'];
		$visitor->token_hash   = (string) $row['token_hash'];
		$visitor->user_id      = (int) $row['user_id'];
		$visitor->name         = (string) $row['name'];
		$visitor->email        = (string) $row['email'];
		$visitor->phone        = (string) $row['phone'];
		$visitor->created_at   = (string) $row['created_at'];
		$visitor->last_seen_at = (string) $row['last_seen_at'];

		// Columns added in schema 7; absent while an upgrade is pending.
		$visitor->current_url   = (string) ( $row['current_url'] ?? '' );
		$visitor->current_title = (string) ( $row['current_title'] ?? '' );
		$cart                   = isset( $row['cart'] ) ? json_decode( (string) $row['cart'], true ) : null;
		$visitor->cart          = is_array( $cart ) ? $cart : null;
		$visitor->context_at    = empty( $row['context_at'] ) ? null : (string) $row['context_at'];

		return $visitor;
	}

	/**
	 * Returns the public fields. The token hash is deliberately left out.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'name'         => $this->name,
			'email'        => $this->email,
			'phone'        => $this->phone,
			'created_at'   => $this->created_at,
			'last_seen_at' => $this->last_seen_at,
		);
	}
}
