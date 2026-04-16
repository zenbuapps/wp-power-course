<?php

declare (strict_types = 1);

namespace J7\PowerCourse;

use J7\PowerCourse\Domain\Product\Events\Edit;
use Kucrut\Vite;
use J7\WpUtils\Classes\General;
use J7\PowerCourse\Utils\Base;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\BundleProduct\Helper;

use J7\Powerhouse\Settings\Model\Settings;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;

/** Class Bootstrap */
final class Bootstrap {
	use \J7\WpUtils\Traits\SingletonTrait;

	const SCHEDULE_ACTION          = 'power_course_schedule_action';
	const SCHEDULE_ACTION_INTERVAL = 10 * MINUTE_IN_SECONDS;

	/** Constructor */
	public function __construct() {
		Compatibility\Compatibility::instance();

		Resources\Loader::instance();
		Resources\Course\AutoGrant::instance();

		Admin\Entry::instance();
		Admin\Product::instance();
		Admin\ProductQuery::instance();

		FrontEnd\MyAccount::instance();

		Api\User::instance();
		Api\Product::instance();
		Api\Course::instance();
		Api\Upload::instance();
		Api\Option::instance();
		Api\Shortcode::instance();
		Api\Comment::instance();
		Api\Reports\Revenue\Api::instance();

		Templates\Templates::instance();
		Templates\Ajax::instance();

		Shortcodes\General::instance();

		PowerEmail\Bootstrap::instance();

		new Edit();

		\add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_script' ], 99 );
		\add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_script' ], 99 );

		// 讓 action scheduler 同時執行的數量增加
		\add_filter( 'action_scheduler_queue_runner_concurrent_batches', fn() => 10 );

		\add_action( 'wp_loaded', [ __CLASS__, 'prevent_guest_checkout' ], 99 );

		// 註冊每5分鐘執行一次的 action scheduler
		\add_action( 'init', [ __CLASS__, 'register_power_course_cron' ] );
	}

	/**
	 * Prevent guest checkout
	 * 當購物車中有課程商品時，不允許訪客結帳
	 *
	 * @return void
	 */
	public static function prevent_guest_checkout(): void {
		// @phpstan-ignore-next-line
		if ( ! \WC() || ! \WC()->cart ) {
			return;
		}
		$cart_items     = \WC()->cart->get_cart_contents();
		$include_course = false;
		foreach ($cart_items as $cart_item) {
			$product_id = (int) ( $cart_item['product_id'] ?? 0 );
			if (!$product_id) {
				continue;
			}

			// 是否為課程商品
			$is_course_product = CourseUtils::is_course_product( $product_id );

			// 是否為銷售方案
			$is_bundle_product = ( Helper::instance( $product_id ) )?->is_bundle_product;

			// 是否有綁開課權限
			$bind_courses_data = \get_post_meta( $product_id, 'bind_courses_data', true );

			if ($is_course_product || $is_bundle_product || $bind_courses_data) {
				// 滿足任何一個條件就避免訪客結帳
				$include_course = true;
				break;
			}
		}

		if (!$include_course) {
			return;
		}

		// 不允許訪客結帳
		$prevent_guest_checkout_options = [
			'woocommerce_enable_guest_checkout'          => 'no',
			'woocommerce_enable_checkout_login_reminder' => 'yes',
			'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
			'woocommerce_registration_generate_password' => 'no',
		];
		foreach ($prevent_guest_checkout_options as $option_name => $option_value) {
			\add_filter("option_{$option_name}", fn() => $option_value, 99);
		}
	}

	/**
	 * 註冊每5分鐘執行一次的 action scheduler
	 *
	 * @return void
	 */
	public static function register_power_course_cron(): void {
		if ( !\function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( !\as_next_scheduled_action( self::SCHEDULE_ACTION ) ) {
			\as_schedule_recurring_action( time(), self::SCHEDULE_ACTION_INTERVAL, self::SCHEDULE_ACTION );
		}
	}

	/**
	 * Admin Enqueue script
	 * You can load the script on demand
	 *
	 * @param string $hook current page hook
	 *
	 * @return void
	 */
	public function admin_enqueue_script( $hook ): void {
		if (!General::in_url([ 'power-course' ])) {
			return;
		}
		self::enqueue_script();
	}

	/**
	 * Enqueue script
	 * You can load the script on demand
	 *
	 * @return void
	 */
	public static function enqueue_script(): void {
		Vite\enqueue_asset(
			Plugin::$dir . '/js/dist',
			'js/src/main.tsx',
			[
				'handle'    => Plugin::$kebab,
				'in-footer' => true,
			]
		);

		// 接線 React bundle 到 power-course text domain，wp_set_script_translations 會自動把 wp-i18n 列為 dependency
		\wp_set_script_translations(
			Plugin::$kebab,
			'power-course',
			Plugin::$dir . '/languages'
		);

		self::inject_script_locale_data();

		$post_id   = \get_the_ID();
		$permalink = $post_id ? \get_permalink( $post_id ) : '';

		/** @var array<string> $active_plugins */
		$active_plugins = \get_option( 'active_plugins', [] );

		$encrypt_env = PowerhouseUtils::simple_encrypt(
			[
				'SITE_URL'                   => \untrailingslashit( \site_url() ),
				'API_URL'                    => \untrailingslashit( \esc_url_raw( rest_url() ) ),
				'CURRENT_USER_ID'            => \get_current_user_id(),
				'CURRENT_POST_ID'            => $post_id,
				'PERMALINK'                  => \untrailingslashit( (string) $permalink ),
				'APP_NAME'                   => Plugin::$app_name,
				'KEBAB'                      => Plugin::$kebab,
				'SNAKE'                      => Plugin::$snake,
				'BUNNY_LIBRARY_ID'           => Settings::instance()->bunny_library_id,
				'BUNNY_CDN_HOSTNAME'         => Settings::instance()->bunny_cdn_hostname,
				'BUNNY_STREAM_API_KEY'       => Settings::instance()->bunny_stream_api_key,
				'NONCE'                      => \wp_create_nonce( 'wp_rest' ),
				'APP1_SELECTOR'              => Base::APP1_SELECTOR,
				'APP2_SELECTOR'              => Base::APP2_SELECTOR,
				'ELEMENTOR_ENABLED'          => \in_array( 'elementor/elementor.php', $active_plugins, true ), // 檢查 elementor 是否啟用
				'COURSE_PERMALINK_STRUCTURE' => CourseUtils::get_course_permalink_structure(),
			]
		);

		\wp_localize_script(
			Plugin::$kebab,
			Plugin::$snake . '_data',
			[
				'env' => $encrypt_env,
			]
		);

		\wp_localize_script(
			Plugin::$kebab,
			'wpApiSettings',
			[
				'root'  => \untrailingslashit( \esc_url_raw( rest_url() ) ),
				'nonce' => \wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Front-end Enqueue script
	 * You can load the script on demand
	 *
	 * @return void
	 */
	public function frontend_enqueue_script(): void {
		self::enqueue_script();
	}

	/**
	 * 注入 wp.i18n.setLocaleData inline script 為 React bundle 載入翻譯資料。
	 *
	 * WP 核心的 script translations 載入機制依賴檔名格式
	 * power-course-{locale}-{md5_of_src_path}.json，但 vite-for-wp 產出的 bundle
	 * 帶 hash 且每次 build 會變，導致 md5 檔名不可預測。改用固定檔名
	 * languages/power-course-{locale}.json（由 scripts/i18n-make-json.mjs 產出），
	 * 在 React bundle 之前呼叫 wp.i18n.setLocaleData 注入翻譯資料。
	 *
	 * @return void
	 */
	private static function inject_script_locale_data(): void {
		self::inject_locale_data_to_handle( Plugin::$kebab );
	}

	/**
	 * 注入 wp.i18n.setLocaleData 到指定 script handle。
	 *
	 * 設定為 public 讓其他模組（如 vanilla TS 前台 bundle）也能共用相同翻譯注入機制。
	 *
	 * @param string $handle script handle
	 * @return void
	 */
	public static function inject_locale_data_to_handle( string $handle ): void {
		$json_file_path = self::resolve_locale_json_path( \determine_locale() );

		if ( null === $json_file_path ) {
			return;
		}

		$json_raw = file_get_contents( $json_file_path );
		if ( ! is_string( $json_raw ) ) {
			return;
		}

		$json_decoded = json_decode( $json_raw, true );
		if ( ! is_array( $json_decoded ) ) {
			return;
		}

		$locale_data = $json_decoded['locale_data'] ?? null;
		if ( ! is_array( $locale_data ) ) {
			return;
		}

		$messages = $locale_data['messages'] ?? null;
		if ( ! is_array( $messages ) ) {
			return;
		}

		\wp_add_inline_script(
			$handle,
			sprintf(
				'( function() { if ( window.wp && window.wp.i18n ) { window.wp.i18n.setLocaleData( %s, %s ); } } )();',
				(string) \wp_json_encode( $messages ),
				(string) \wp_json_encode( 'power-course' )
			),
			'before'
		);
	}

	/**
	 * 解析 locale JSON 檔案路徑，支援 fallback 鏈。
	 *
	 * 避免 admin user locale 與 site locale 不一致時 React bundle 退化成英文 msgid：
	 *
	 *   1. current locale（determine_locale() 結果，通常是 user profile locale）
	 *   2. site locale（get_locale()，對應 Settings → General → Site Language）
	 *   3. 回 null — 不注入翻譯，React 顯示英文 msgid（WP 預設行為）
	 *
	 * 刻意不做 glob 保底：若找不到對應 locale 的 JSON 就回 null，
	 * 否則切換語系為 en_US 時仍會強制載入 zh_TW 翻譯，導致語系切換失效。
	 *
	 * @param string $locale 當前 determine_locale() 回傳值
	 * @return string|null   JSON 絕對路徑；若完全找不到則回 null
	 */
	private static function resolve_locale_json_path( string $locale ): ?string {
		$languages_dir = Plugin::$dir . '/languages';

		// 1. 當前 locale
		$primary = "{$languages_dir}/power-course-{$locale}.json";
		if ( file_exists( $primary ) ) {
			return $primary;
		}

		// 2. site locale（若與 current locale 不同）
		$site_locale = \get_locale();
		if ( $site_locale !== $locale ) {
			$site_fallback = "{$languages_dir}/power-course-{$site_locale}.json";
			if ( file_exists( $site_fallback ) ) {
				return $site_fallback;
			}
		}

		return null;
	}
}
