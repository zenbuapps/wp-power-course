<?php
/**
 * Admin Entry
 */

declare(strict_types=1);

namespace J7\PowerCourse\Admin;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\Utils\Base;
use J7\Powerhouse\Utils\Base as PowerhouseBase;



/**
 * Class Entry
 */
final class Entry {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		// Add the admin page for full-screen.
		\add_action('current_screen', [ __CLASS__, 'maybe_output_admin_page' ], 10);
	}

	/**
	 * Output the dashboard admin page.
	 */
	public static function maybe_output_admin_page(): void {
		// Exit if not in admin.
		if (!\is_admin()) {
			return;
		}

		// Make sure we're on the right screen.
		$screen = \get_current_screen();

		if (Plugin::$kebab !== $screen?->id) {
			return;
		}

		self::render_page();

		exit;
	}

	/**
	 * Output landing page header.
	 *
	 * Credit: SliceWP Setup Wizard.
	 */
	public static function render_page(): void {
		// Output header HTML.
		Bootstrap::enqueue_script();
		$blog_name = \get_bloginfo('name');
		$id        = substr(Base::APP1_SELECTOR, 1);
		PowerhouseBase::render_admin_layout(
			[
				'title' => "課程後台 | {$blog_name}",
				'id'    => $id,
			]
			);
	}
}
