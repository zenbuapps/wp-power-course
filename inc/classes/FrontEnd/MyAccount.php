<?php

declare( strict_types=1 );

namespace J7\PowerCourse\FrontEnd;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Front-end MyAccount Page
 * 我的課程 / 我的通行證
 */
final class MyAccount {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const COURSES_ENDPOINT = 'courses';

	public const PASSES_ENDPOINT = 'access-passes';

	/**
	 * 記錄 rewrite rules 版本的 option key
	 *
	 * 說明：add_rewrite_endpoint() 只是把規則加進 WP 的 rewrite 陣列，必須 flush 一次才會寫進 DB。
	 * 外掛「更新版本」（覆蓋檔案）不會重跑 activate()，沒有這道閘門的話，
	 * 既有站台的新 endpoint 會一直 404。
	 */
	private const REWRITE_VERSION_OPTION = 'pc_myaccount_rewrite_version';

	/**
	 * 目前的 rewrite 版本
	 *
	 * 1.1.0 — 新增 access-passes endpoint（我的通行證）
	 */
	private const REWRITE_VERSION = '1.1.0';

	/** @var bool 是否顯示「我的課程」 */
	private bool $show_courses = true;

	/** @var bool 是否顯示「我的通行證」 */
	private bool $show_passes = true;

	/** Constructor */
	public function __construct() {
		$settings = Settings::instance();

		$this->show_courses = 'yes' !== $settings->hide_myaccount_courses;
		$this->show_passes  = 'yes' !== $settings->hide_myaccount_access_passes;

		// 兩個分頁都關掉就完全不介入 My Account（維持既有行為：不註冊任何 rewrite endpoint）
		if ( ! $this->show_courses && ! $this->show_passes ) {
			return;
		}

		\add_action( 'init', [ $this, 'custom_account_endpoint' ] );
		\add_action( 'admin_init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );

		if ( $this->show_courses ) {
			\add_filter( 'woocommerce_account_menu_items', [ $this, 'courses_menu_items' ], 100, 1 );
			\add_action(
				'woocommerce_account_' . self::COURSES_ENDPOINT . '_endpoint',
				[ $this, 'render_courses' ]
			);
		}

		if ( $this->show_passes ) {
			// priority 101：跑在 courses_menu_items 之後，才找得到「我的課程」並插在它後面
			\add_filter( 'woocommerce_account_menu_items', [ $this, 'passes_menu_items' ], 101, 1 );
			\add_action(
				'woocommerce_account_' . self::PASSES_ENDPOINT . '_endpoint',
				[ $this, 'render_passes' ]
			);
		}
	}

	/**
	 * Custom account endpoint 我的課程 / 我的通行證
	 */
	public function custom_account_endpoint(): void {
		if ( $this->show_courses ) {
			// @phpstan-ignore-next-line
			\add_rewrite_endpoint( self::COURSES_ENDPOINT, EP_ROOT | EP_PAGES );
		}

		if ( $this->show_passes ) {
			// @phpstan-ignore-next-line
			\add_rewrite_endpoint( self::PASSES_ENDPOINT, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * 版本閘門：endpoint 有變動時 flush 一次 rewrite rules
	 *
	 * 掛 admin_init（跑在 init 之後），此時 add_rewrite_endpoint 已註冊完畢。
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( self::REWRITE_VERSION === \get_option( self::REWRITE_VERSION_OPTION ) ) {
			return;
		}

		\flush_rewrite_rules();
		\update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Add menu item 我的課程
	 *
	 * @param array<string> $items Menu items.
	 *
	 * @return array<string>
	 */
	public function courses_menu_items( array $items ): array {
		// 重新排序，排在控制台後
		return array_slice( $items, 0, 1, true ) + [
			self::COURSES_ENDPOINT => __(
				'My Courses',
				'power-course'
			),
		] + array_slice( $items, 1, null, true );
	}

	/**
	 * Add menu item 我的通行證
	 *
	 * 插在「我的課程」之後；若「我的課程」被關閉，退回排在控制台後。
	 *
	 * @param array<string> $items Menu items.
	 *
	 * @return array<string>
	 */
	public function passes_menu_items( array $items ): array {
		$position = array_search( self::COURSES_ENDPOINT, array_keys( $items ), true );
		$offset   = false === $position ? 1 : ( (int) $position + 1 );

		return array_slice( $items, 0, $offset, true ) + [
			self::PASSES_ENDPOINT => __(
				'My Access Passes',
				'power-course'
			),
		] + array_slice( $items, $offset, null, true );
	}

	/**
	 * Render courses
	 */
	public function render_courses(): void {
		echo '<div class="tailwind">';
		Plugin::load_template( 'my-account' );
		echo '</div>';
	}

	/**
	 * Render access passes
	 */
	public function render_passes(): void {
		echo '<div class="tailwind">';
		Plugin::load_template( 'my-passes' );
		echo '</div>';
	}
}
