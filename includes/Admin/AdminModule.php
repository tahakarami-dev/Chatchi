<?php
/**
 * Agent panel entry in wp-admin.
 *
 * @package ChatChi
 */

namespace ChatChi\Admin;

use ChatChi\Core\Contracts\Module;
use ChatChi\Database\Repositories\ConversationRepository;
use ChatChi\Models\Conversation;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the ChatChi menu item and serves the standalone panel.
 *
 * The page lives at wp-admin/admin.php?page=chatchi, so WordPress login,
 * capability checks and any security plugins protecting wp-admin all apply.
 * Its content is printed on the page's load hook, before the admin header,
 * and the request ends there.
 */
final class AdminModule implements Module {

	public const PAGE_SLUG = 'chatchi';

	private PanelAccess $access;

	private PanelPage $page;

	private ConversationRepository $conversations;

	/**
	 * @param PanelAccess            $access        Panel capability.
	 * @param PanelPage              $page          Panel document.
	 * @param ConversationRepository $conversations Conversation storage, for the menu badge.
	 */
	public function __construct( PanelAccess $access, PanelPage $page, ConversationRepository $conversations ) {
		$this->access        = $access;
		$this->page          = $page;
		$this->conversations = $conversations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this->access, 'grant' ), 10, 4 );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level menu item with a badge for new conversations.
	 */
	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'ChatChi', 'chatchi' ),
			__( 'ChatChi', 'chatchi' ) . $this->badge(),
			PanelAccess::CAPABILITY,
			self::PAGE_SLUG,
			'__return_null',
			'dashicons-format-chat',
			3
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'serve' ) );
		}
	}

	/**
	 * Prints the standalone panel and ends the request.
	 */
	public function serve(): void {
		if ( ! current_user_can( PanelAccess::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'chatchi' ), 403 );
		}

		$this->access->ensure_admin_agent( wp_get_current_user() );

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$this->page->render();
		exit;
	}

	/**
	 * Returns the menu badge markup, or an empty string.
	 */
	private function badge(): string {
		if ( ! current_user_can( PanelAccess::CAPABILITY ) ) {
			return '';
		}

		try {
			$count = $this->conversations->count_by_status()[ Conversation::STATUS_NEW ];
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( $count <= 0 ) {
			return '';
		}

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
			(int) $count,
			esc_html( number_format_i18n( $count ) )
		);
	}
}
