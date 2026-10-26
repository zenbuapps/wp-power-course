<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Templates;

use J7\PowerCourse\Plugin;
use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Settings\Model\Settings;





/**
 *  Template for AJAX
 */
final class Ajax {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** 前台 utility CSS 的 handle（Issue #285） */
	public const UTILITY_STYLE_HANDLE = 'power-course-front-utilities';

	/** Powerhouse 前台 CSS 的 handle（見 Powerhouse `Bootstrap::enqueue_frontend_assets()`） */
	public const POWERHOUSE_FRONT_STYLE_HANDLE = 'powerhouse_front';

	/**
	 * Ajax Actions
	 *
	 * @deprecated 2024-12-30
	 * @var string[]
	 */
	public $actions = [ 'finish_chapter' ];

	/** Constructor */
	public function __construct() {
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'wp_enqueue_scripts' ], -10 );

		$actions = $this->actions;
		foreach ( $actions as $action ) {
			\add_action( "wp_ajax_{$action}", [ $this, "{$action}_callback" ] ); // @phpstan-ignore-line
			\add_action( "wp_ajax_nopriv_{$action}", [ $this, "{$action}_callback" ] ); // @phpstan-ignore-line
		}
	}

	/**
	 * Enqueue assets
	 *
	 * @return void
	 */
	public static function wp_enqueue_scripts(): void {
		\wp_enqueue_script( 'wc-add-to-cart' );
		\wp_enqueue_script(
			Plugin::$kebab . '-template',
			Plugin::$url . '/inc/assets/dist/index.js',
			[ 'jquery', 'wp-i18n' ],
			Plugin::$version,
			[
				'strategy' => 'defer',
			]
		);
		// 不使用 PluginTrait::add_module_handle()，改用安全的 script_loader_tag filter。
		// 理由同 Bootstrap.php：add_type_attribute 會以 sprintf 覆蓋整個 $tag，
		// 在 WP 6.9+ 中摧毀 wp_add_inline_script 注入的 setLocaleData 翻譯。
		self::add_safe_module_type(Plugin::$kebab . '-template');

		// 接線前台 vanilla TS bundle 到 power-course text domain
		\wp_set_script_translations(
			Plugin::$kebab . '-template',
			'power-course',
			Plugin::$dir . '/languages'
		);
		\J7\PowerCourse\Bootstrap::inject_locale_data_to_handle( Plugin::$kebab . '-template' );

		$is_avl = CourseUtils::is_avl();

		$settings = Settings::instance();
		\wp_localize_script(
			Plugin::$kebab . '-template',
			'pc_data',
			[
				'ajax_url'                         => \admin_url('admin-ajax.php'),
				'nonce'                            => \wp_create_nonce( 'wp_rest' ),
				'is_avl'                           => $is_avl,
				'header_offset'                    => $settings->pc_header_offset,
				'fix_video_and_tabs_mobile'        => $settings->fix_video_and_tabs_mobile,
				// 行動版吸附對象（Issue #278）；option 可能存了不合法的舊值，輸出前再過一次白名單
				'fix_video_and_tabs_mobile_target' => Settings::normalize_sticky_target( $settings->fix_video_and_tabs_mobile_target ),
			]
			);

		\wp_enqueue_style('blocknote');

		self::enqueue_utility_style();
	}

	/**
	 * 載入 Power Course 自產的前台 utility CSS（Issue #285）
	 *
	 * 前台模板用的 Tailwind / daisyUI class 原本全靠 Powerhouse 的 front.min.css，
	 * 而那份 CSS 只在 Powerhouse 建置時旁邊剛好有 power-course 才掃得到（3.6.0 就整批漏掉）。
	 * 改由我們自己掃模板產出（`pnpm run build:css:front`，設定見 `tailwind.config.front.cjs`）。
	 *
	 * 必須排在 `powerhouse_front` **之後**：兩份 CSS 的選擇器 specificity 相同，
	 * 我們這份裡「元件 → utility」的順序是自洽的，排在後面才能保證 Power Course 的元素
	 * 不受 Powerhouse 那份缺漏或版本錯位影響。全站載入：短碼可以放在任何頁面。
	 *
	 * @return void
	 */
	public static function enqueue_utility_style(): void {
		$css_path = Plugin::$dir . '/inc/assets/dist/css/front-utilities.min.css';
		// 沒 build 過（例如只跑了 vite build）就不載入，避免輸出 404 的 <link>
		if ( ! file_exists( $css_path ) ) {
			return;
		}

		// 只在 Powerhouse 有註冊時才宣告相依：WP 遇到未註冊的相依 handle 會連本身都不輸出
		$deps = \wp_style_is( self::POWERHOUSE_FRONT_STYLE_HANDLE, 'registered' ) ? [ self::POWERHOUSE_FRONT_STYLE_HANDLE ] : [];

		\wp_enqueue_style(
			self::UTILITY_STYLE_HANDLE,
			Plugin::$url . '/inc/assets/dist/css/front-utilities.min.css',
			$deps,
			(string) filemtime( $css_path )
		);
	}

	/**
	 * 條件式載入 Swiper bundle 的 CSS / JS
	 *
	 * Issue #10：僅在課程銷售頁存在 2 部以上 trial_videos 時才載入 Swiper bundle，
	 * 避免影響 1 部試看影片或無試看影片頁面的效能。
	 *
	 * @return void
	 */
	public static function enqueue_swiper_assets(): void {
		$handle = Plugin::$kebab . '-trial-videos-swiper';
		if ( \wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		// 用 dist 檔案的 mtime 當 ver，build 後自動 cache bust，避免 dev 期間
		// 改了 css/js 但 Plugin::$version 沒動導致瀏覽器 / 邊緣快取拿到舊資源。
		$css_path = Plugin::$dir . '/inc/assets/dist/css/trial-videos-swiper.css';
		$js_path  = Plugin::$dir . '/inc/assets/dist/trial-videos-swiper.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : Plugin::$version;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : Plugin::$version;

		\wp_enqueue_style(
			$handle,
			Plugin::$url . '/inc/assets/dist/css/trial-videos-swiper.css',
			[],
			$css_ver
		);

		\wp_enqueue_script(
			$handle,
			Plugin::$url . '/inc/assets/dist/trial-videos-swiper.js',
			[ 'wp-i18n' ],
			$js_ver,
			[
				'strategy' => 'defer',
			]
		);

		self::add_safe_module_type( $handle );

		\wp_set_script_translations(
			$handle,
			'power-course',
			Plugin::$dir . '/languages'
		);
		\J7\PowerCourse\Bootstrap::inject_locale_data_to_handle( $handle );
	}

	/**
	 * 安全地為 script handle 加上 type="module"，不破壞 inline scripts。
	 *
	 * 替代 PluginTrait::add_module_handle()：該方法的 add_type_attribute filter
	 * 在 WordPress 6.9+ 會以 sprintf 覆蓋整個 $tag（含 translations + before/after inline scripts），
	 * 導致 wp_add_inline_script 注入的 setLocaleData 翻譯被摧毀。
	 *
	 * 此方法使用 WP_HTML_Tag_Processor 僅修改帶 src 的 <script> tag，保留所有 inline scripts。
	 *
	 * @param string $handle script handle
	 * @return void
	 */
	private static function add_safe_module_type( string $handle ): void {
		\add_filter(
			'script_loader_tag',
			function ( string $tag, string $current_handle ) use ( $handle ): string {
				if ($current_handle !== $handle) {
					return $tag;
				}

				$processor = new \WP_HTML_Tag_Processor($tag);
				while ($processor->next_tag('script')) {
					if ($processor->get_attribute('src')) {
						$processor->set_attribute('type', 'module');
						break;
					}
				}

				return $processor->get_updated_html();
			},
			10,
			2
		);
	}

	/**
	 * Finish Chapter Callback
	 *
	 * @deprecated 2024-12-30
	 * @return void
	 */
	public function finish_chapter_callback(): void {
		// 確保請求來自有效的來源
		\check_ajax_referer(Plugin::$snake, 'security');

		// 獲取從前端發送的數據
		$data = WP::sanitize_text_field_deep($_POST);

		// @phpstan-ignore-next-line
		$chapter_id = (int) @$data['data']['chapter_id'];

		if ( ! $chapter_id) {
			\wp_send_json_error(
				[
					'code'    => '400',
					'message' => __( 'Missing chapter ID or course ID.', 'power-course' ),
				]
			);

		}

		AVLChapterMeta::add(
			(int) $chapter_id,
			\get_current_user_id(),
			'finished_at',
			\wp_date('Y-m-d H:i:s')
		);

		// 發送回應
		\wp_send_json_success(
			[
				'code'    => '200',
				'message' => __( 'Chapter completed', 'power-course' ),
			]
		);

		// 重要：總是在 AJAX 函數結束時調用 wp_die()
		// @phpstan-ignore-next-line
		\wp_die();
	}
}
