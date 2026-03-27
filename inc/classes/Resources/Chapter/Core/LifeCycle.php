<?php
/**
 * Chapter 生命週期相關
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Core;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\LifeCycle as CourseLifeCycle;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD;
use J7\Powerhouse\Domains\Post\Utils as PostUtils;

/**
 * Class LifeCycle
 */
final class LifeCycle {
	use \J7\WpUtils\Traits\SingletonTrait;

	const CHAPTER_ENTERED_ACTION = 'power_course_visit_chapter';

	const CHAPTER_FINISHED_ACTION     = 'power_course_chapter_finished';
	const CHAPTER_UNFINISHEDED_ACTION = 'power_course_chapter_unfinished';


	/** Constructor */
	public function __construct() {

		\add_action( 'power_course_before_classroom_render', [ __CLASS__, 'register_visit_chapter' ] );

		// 進入章節時要註記
		\add_action( self::CHAPTER_ENTERED_ACTION, [ __CLASS__, 'save_first_visit_time' ], 10, 2 );

		\add_action( self::CHAPTER_ENTERED_ACTION, [ __CLASS__, 'save_last_visit_info' ], 10, 2 );

		// 上完章節後要寫入 log
		\add_action( self::CHAPTER_FINISHED_ACTION, [ __CLASS__, 'add_chapter_finish_log' ], 10, 3 );

		// 標示為未完成
		\add_action( self::CHAPTER_UNFINISHEDED_ACTION, [ __CLASS__, 'add_chapter_unfinished_log' ], 10, 3 );

		// 每次儲存前
		\add_action('save_post_' . CPT::POST_TYPE, [ __CLASS__, 'delete_transient' ], 10, 3);
		\add_action('save_post_' . CPT::POST_TYPE, [ __CLASS__, 'delete_elementor_data' ], 10, 3);
	}

	/**
	 * 註冊進入章節的動作
	 */
	public static function register_visit_chapter(): void {
		global $product, $chapter, $course;
		$product = $course ?? $product;
		if ( ! $product || ! $chapter ) {
			return;
		}

		$is_avl = ChapterUtils::is_avl();

		if ( !$is_avl ) {
			return;
		}

		\do_action( self::CHAPTER_ENTERED_ACTION, $chapter, $product );
	}

	/**
	 * 進入章節時要註記
	 *
	 * @param \WP_Post    $chapter 章節文章物件
	 * @param \WC_Product $product 課程
	 */
	public static function save_first_visit_time( $chapter, $product ): void {
		$meta_key = 'first_visit_at';
		$user_id  = \get_current_user_id();

		$enter_time = MetaCRUD::get( $chapter->ID, $user_id, $meta_key, true );

		// 檢查之前有沒有紀錄，有就返回
		if ( $enter_time ) {
			return;
		}

		MetaCRUD::update( $chapter->ID, $user_id, $meta_key, \wp_date( 'Y-m-d H:i:s' ) );
		self::add_chapter_enter_log( $chapter, $product );
	}

	/**
	 * 進入章節時要註記
	 *
	 * @param \WP_Post    $chapter 章節文章物件
	 * @param \WC_Product $product 課程
	 */
	public static function add_chapter_enter_log( $chapter, $product ): void {
		$crud  = StudentLogCRUD::instance();
		$title = \get_the_title($chapter->ID);
		$crud->add(
			[
				'user_id'    => (string) \get_current_user_id(),
				'course_id'  => (string) $product->get_id(),
				'chapter_id' => (string) $chapter->ID,
				'title'      => "首次進入章節 《{$title}》 #{$chapter->ID}",
				'content'    => '',
				'log_type'   => AtHelper::CHAPTER_ENTERED,
			]
			);
	}

	/**
	 * 註冊離開章節的動作
	 *
	 * @param \WP_Post    $chapter 章節文章物件
	 * @param \WC_Product $product 課程
	 */
	public static function save_last_visit_info( $chapter, $product ): void {
		$meta_key   = 'last_visit_info';
		$meta_value = [
			'chapter_id'    => $chapter->ID,
			'last_visit_at' => \wp_date( 'Y-m-d H:i:s' ),
		];
		$user_id    = \get_current_user_id();

		AVLCourseMeta::update( $product->get_id(), $user_id, $meta_key, $meta_value );
	}

	/**
	 * 完成章節時要註記
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id 課程 ID
	 * @param int $user_id 用戶 ID
	 */
	public static function add_chapter_finish_log( int $chapter_id, int $course_id, int $user_id ): void {
		$crud  = StudentLogCRUD::instance();
		$title = \get_the_title($chapter_id);
		$crud->add(
			[
				'user_id'    => (string) $user_id,
				'course_id'  => (string) $course_id,
				'chapter_id' => (string) $chapter_id,
				'title'      => "完成章節 《{$title}》 #{$chapter_id}",
				'content'    => '',
				'log_type'   => AtHelper::CHAPTER_FINISHED,
			]
		);

		$progress = CourseUtils::get_course_progress( $course_id );
		if ( $progress == (float) 100 ) {
			\do_action( CourseLifeCycle::COURSE_FINISHED_ACTION, $course_id, $user_id );
		}
	}

	/**
	 * 標示為未完成
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id 課程 ID
	 * @param int $user_id 用戶 ID
	 */
	public static function add_chapter_unfinished_log( int $chapter_id, int $course_id, int $user_id ): void {
		$crud  = StudentLogCRUD::instance();
		$title = \get_the_title($chapter_id);
		$crud->add(
			[
				'user_id'    => (string) $user_id,
				'course_id'  => (string) $course_id,
				'chapter_id' => (string) $chapter_id,
				'title'      => "章節標示為未完成 《{$title}》 #{$chapter_id}",
				'content'    => '',
				'log_type'   => AtHelper::CHAPTER_UNFINISHED,
			]
		);

		$progress = CourseUtils::get_course_progress( $course_id );
		if ( $progress == (float) 100 ) {
			\do_action( CourseLifeCycle::COURSE_FINISHED_ACTION, $course_id, $user_id );
		}
	}

	/**
	 * 處理文章儲存後的動作
	 *
	 * @param int      $post_id Post ID
	 * @param \WP_Post $post Post object
	 * @param bool     $update Whether this is an existing post being updated
	 */
	public static function delete_transient( $post_id, $post, $update ): void {
		// 避免自動儲存
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// 清除快取
		$top_parent_id = PostUtils::get_top_post_id( $post_id );
		$course_id     = (int) \get_post_meta( $top_parent_id, 'parent_course_id', true );
		$cache_key     = ChapterUtils::get_cache_key( $course_id );
		\delete_transient( $cache_key );
		\wp_cache_flush_group('prev_next');
	}


	/**
	 * 如果儲存時，editor 是 power-editor，則要清除 elementor 相關資料
	 *
	 * @param int      $post_id Post ID
	 * @param \WP_Post $post Post object
	 * @param bool     $update Whether this is an existing post being updated
	 */
	public static function delete_elementor_data( $post_id, $post, $update ): void {
		// 避免自動儲存
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		$editor = \get_post_meta( $post_id, 'editor', true );

		if ( $editor === 'power-editor' ) {
			$post_meta = \get_post_meta( $post_id );

			if (is_array($post_meta)) {
				foreach ( $post_meta as $key => $value ) {
					if ( strpos( (string) $key, '_elementor_' ) !== false ) {
						\delete_post_meta( $post_id, (string) $key );
					}
				}
			}
		}
	}
}
