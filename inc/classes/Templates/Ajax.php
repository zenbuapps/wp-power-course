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
				'ajax_url'                  => \admin_url('admin-ajax.php'),
				'nonce'                     => \wp_create_nonce( 'wp_rest' ),
				'is_avl'                    => $is_avl,
				'header_offset'             => $settings->pc_header_offset,
				'fix_video_and_tabs_mobile' => $settings->fix_video_and_tabs_mobile,
			]
			);

		\wp_enqueue_style('blocknote');
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

		\wp_enqueue_style(
			$handle,
			Plugin::$url . '/inc/assets/dist/css/trial-videos-swiper.css',
			[],
			Plugin::$version
		);

		\wp_enqueue_script(
			$handle,
			Plugin::$url . '/inc/assets/dist/trial-videos-swiper.js',
			[ 'wp-i18n' ],
			Plugin::$version,
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
	private static function add_safe_module_type(string $handle): void
	{
		\add_filter(
			'script_loader_tag',
			function (string $tag, string $current_handle) use ($handle): string {
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
