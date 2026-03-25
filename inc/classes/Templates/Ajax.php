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
			[ 'jquery' ],
			Plugin::$version,
			[
				'strategy' => 'defer',
			]
		);
		Plugin::instance()->add_module_handle(Plugin::$kebab . '-template', 'defer');

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
					'message' => '缺少 chapter ID 或 course ID.',
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
				'message' => '章節已完成',
			]
		);

		// 重要：總是在 AJAX 函數結束時調用 wp_die()
		// @phpstan-ignore-next-line
		\wp_die();
	}
}
