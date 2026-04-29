<?php
/**
 * Course 生命週期相關
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Resources\Chapter\Core\LifeCycle as ChapterLifeCycle;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;
use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;
use J7\PowerCourse\Resources\ChapterProgress\Service\Service as ChapterProgressService;
/**
 * Class LifeCycle
 */
final class LifeCycle {
	use \J7\WpUtils\Traits\SingletonTrait;

	// 開通用戶權限的鉤子
	const ADD_STUDENT_TO_COURSE_ACTION = 'power_course_add_student_to_course';
	// 開通用戶權限後
	const AFTER_ADD_STUDENT_TO_COURSE_ACTION = 'power_course_after_add_student_to_course';
	// 課程開課的鉤子
	const COURSE_LAUNCHED_ACTION = 'power_course_course_launch';
	// 課程更新前
	const BEFORE_UPDATE_PRODUCT_META_ACTION = 'power_course_before_update_product_meta';
	// 移除學員後
	const AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION = 'power_course_after_remove_student_from_course';
	// 更新學員觀看後
	const AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION = 'power_course_after_update_student_from_course';
	// 課程完成
	const COURSE_FINISHED_ACTION = 'power_course_course_finished';

	/** Constructor */
	public function __construct() {

		// 購買了有開課權限的商品時
		\add_action( self::ADD_STUDENT_TO_COURSE_ACTION, [ __CLASS__, 'add_order_created_log' ], 10, 4 );
		// 開通課程權限
		\add_action( self::ADD_STUDENT_TO_COURSE_ACTION, [ __CLASS__, 'add_student_to_course' ], 20, 4 );

		// 開通課程權限後
		\add_action(self::AFTER_ADD_STUDENT_TO_COURSE_ACTION, [ __CLASS__, 'add_course_granted_log' ], 10, 4);

		// 刪除課程
		\add_action('before_delete_post', [ __CLASS__, 'delete_course_and_related_items' ], 10, 2);
		\add_action('trashed_post', [ __CLASS__, 'delete_course_and_related_items' ], 10, 2);
		\add_action('untrash_post', [ __CLASS__, 'untrash_course_and_related_items' ], 10, 2);

		// 課程更新時，清除寄信註記
		\add_action(self::BEFORE_UPDATE_PRODUCT_META_ACTION, [ __CLASS__, 'clear_course_launch_action_done' ], 10, 2);

		// 使用 power-editor 時刪除 elementor 資料 (商品 API觸發)
		\add_action(self::BEFORE_UPDATE_PRODUCT_META_ACTION, [ __CLASS__, 'delete_elementor_data' ], 10, 2);
		// 使用 power-editor 時刪除 elementor 資料 (Post API觸發)
		\add_action('save_post_product', [ ChapterLifeCycle::class, 'delete_elementor_data' ], 10, 3);

		// 註冊課程開課 hook，透過定時任務去看課程開課時機
		\add_action( Bootstrap::SCHEDULE_ACTION, [ __CLASS__, 'register_course_launch' ], 10, 1 );
		\add_action( self::COURSE_LAUNCHED_ACTION, [ __CLASS__, 'add_course_launch_log' ], 20, 2 );

		// 移除學員後，將課程以後權的發信改為 mark_as_sent 改成 0
		\add_action(self::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, [ __CLASS__, 'save_meta_remove_student' ], 10, 2);
		\add_action(self::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, [ __CLASS__, 'update_email_mark_as_sent' ], 20, 2);

		// 直接更新學員觀看課程的時間
		\add_action(self::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION, [ __CLASS__, 'save_meta_update_student' ], 10, 3);

		// 課程完成
		\add_action( self::COURSE_FINISHED_ACTION, [ __CLASS__, 'save_finished_time' ], 10, 2 );
	}



	/**
	 * 購買了有開課權限的商品時，寫入 log
	 *
	 * @param int        $user_id 用戶 id
	 * @param int        $course_id 課程 id
	 * @param int|string $expire_date 到期日 10位 timestamp | subscription_{訂閱id}
	 * @param ?\WC_Order $order 訂單
	 * @return void
	 * @throws \Exception 新增學員失敗
	 */
	public static function add_order_created_log( int $user_id, int $course_id, int|string $expire_date, ?\WC_Order $order ): void {
		if (!$order) {
			return;
		}

		$crud = StudentLogCRUD::instance();
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => sprintf(
					/* translators: 1: 課程 ID, 2: 訂單 ID */
					esc_html__( 'Purchased product including course #%1$d access via order #%2$d', 'power-course' ),
					$course_id,
					$order->get_id()
				),
				'content'   => '',
				'log_type'  => AtHelper::ORDER_CREATED,
			]
			);
	}

	/**
	 * 新增學員到課程，開通用戶課程權限
	 *
	 * @param int            $user_id 用戶 id
	 * @param int            $course_id 課程 id
	 * @param int|string     $expire_date 到期日 10位 timestamp | subscription_{訂閱id}
	 * @param \WC_Order|null $order 訂單
	 * @return void
	 * @throws \Exception 新增學員失敗
	 */
	public static function add_student_to_course( int $user_id, int $course_id, int|string $expire_date, $order ): void {
		global $wpdb;
		// 開始事務
		$wpdb->query( 'START TRANSACTION' );

		try {
			$current_avl_course_ids = \get_user_meta( $user_id, 'avl_course_ids' );
			if (!\is_array($current_avl_course_ids)) {
				$current_avl_course_ids = [];
			}
			// 先檢查用戶有沒有買過，沒買過才新增 user_meta
			if (!\in_array($course_id, $current_avl_course_ids)) {
				\add_user_meta( $user_id, 'avl_course_ids', $course_id, false );
			}

			$update_success1 = AVLCourseMeta::update( (int) $course_id, (int) $user_id, 'expire_date', $expire_date );
			if (false === $update_success1) {
				throw new \Exception(
					sprintf(
						/* translators: 1: 課程 ID, 2: 用戶 ID, 3: 到期日 */
						esc_html__( 'Failed to update course expire date course_id: %1$d user_id: %2$d expire_date: %3$s', 'power-course' ),
						$course_id,
						$user_id,
						(string) $expire_date
					)
				);
			}
			$at_helper       = new AtHelper(AtHelper::COURSE_GRANTED);
			$update_success2 = AVLCourseMeta::update( (int) $course_id, (int) $user_id, $at_helper->meta_key_at, \wp_date('Y-m-d H:i:s') ); // 紀錄 local time
			if (false === $update_success2) {
				throw new \Exception(
					sprintf(
						/* translators: 1: 課程 ID, 2: 用戶 ID, 3: meta key */
						esc_html__( 'Failed to update course grant time course_id: %1$d user_id: %2$d meta_key: %3$s', 'power-course' ),
						$course_id,
						$user_id,
						(string) $at_helper->meta_key_at
					)
				);
			}

			\do_action(self::AFTER_ADD_STUDENT_TO_COURSE_ACTION, $user_id, $course_id, $expire_date, $order);

			// 提交事務
			$wpdb->query( 'COMMIT' );
		} catch (\Throwable $th) {
			$wpdb->query( 'ROLLBACK' );
			throw new \Exception(
				sprintf(
					/* translators: %s: 錯誤訊息 */
					esc_html__( 'Failed to add student: %s', 'power-course' ),
					$th->getMessage()
				)
			);
		}
	}

	/**
	 * 獲得課權限時，寫入 log
	 *
	 * @param int        $user_id 用戶 id
	 * @param int        $course_id 課程 id
	 * @param int|string $expire_date 到期日 10位 timestamp | subscription_{訂閱id}
	 * @param ?\WC_Order $order 訂單
	 * @return void
	 * @throws \Exception 新增學員失敗
	 */
	public static function add_course_granted_log( int $user_id, int $course_id, int|string $expire_date, ?\WC_Order $order ): void {
		$crud        = StudentLogCRUD::instance();
		$expire_date = new ExpireDate($expire_date);
		$order_label = $order
		? sprintf(
				/* translators: %d: 訂單 ID */
				esc_html__( 'via order #%d', 'power-course' ),
				$order->get_id()
			)
		: esc_html__( 'via admin manual grant', 'power-course' );
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => sprintf(
					/* translators: 1: 授權來源, 2: 課程 ID, 3: 到期日 */
					esc_html__( '%1$s granted course #%2$d access, expire date %3$s', 'power-course' ),
					$order_label,
					$course_id,
					$expire_date->expire_date_label
				),
				'content'   => '',
				'log_type'  => AtHelper::COURSE_GRANTED,
			]
			);
	}

	/**
	 * 課程開課時，寫入 log
	 *
	 * @param int $user_id 用戶 id
	 * @param int $course_id 課程 id
	 * @return void
	 * @throws \Exception 新增學員失敗
	 */
	public static function add_course_launch_log( int $user_id, int $course_id ): void {
		$crud = StudentLogCRUD::instance();
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => sprintf(
					/* translators: %d: 課程 ID */
					esc_html__( 'Course #%d started', 'power-course' ),
					$course_id
				),
				'content'   => '',
				'log_type'  => AtHelper::COURSE_LAUNCHED,
			]
			);
	}

	/**
	 * 刪除課程與相關項目
	 * 刪除課程時連帶刪除子章節以及銷售方案(bundle product)
	 *
	 * @param int                  $id 課程 id
	 * @param string|\WP_Post|null $post_or_previous_status WP_Post(delete_post) 或 前一個狀態(trashed_post)
	 * @return array<int> 刪除的 post id
	 */
	public static function delete_course_and_related_items( int $id, $post_or_previous_status = null ): array {
		$post_type = \get_post_type( $id );
		$is_course = \get_post_meta( $id, '_is_course', true ) === 'yes';

		if ( !$is_course && $post_type !== ChapterCPT::POST_TYPE ) {
			return [];
		}

		$chapter_ids      = (array) CourseUtils::get_all_chapters( $id, true, [ 'any', 'trash' ] );
		$bundle_ids       = Helper::get_bundle_products(  $id, true, [ 'any', 'trash' ] );
		$deleted_post_ids = [];

		foreach ([ ...$chapter_ids, ...$bundle_ids ] as $post_id) {
			/** @var int|\WP_Post|\WC_Product $post_id */
			$int_post_id = is_object($post_id) ? ( $post_id instanceof \WC_Product ? $post_id->get_id() : (int) $post_id->ID ) : (int) $post_id;
			$result      = \wp_trash_post( $int_post_id );
			if ( $result ) {
				$deleted_post_ids[] = $int_post_id;
			}
		}

		self::delete_avl_course_by_course_id( $id );

		self::delete_bind_course_data_by_course_id( $id );

		return $deleted_post_ids;
	}

	/**
	 * 移除所有能上 $course_id 的用戶權限
	 * 刪除課程時，連動刪除
	 *
	 * @param int $course_id 課程 id
	 */
	private static function delete_avl_course_by_course_id( int $course_id ): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'usermeta',
			[
				'meta_key'   => 'avl_course_ids' ,
				'meta_value' => $course_id,
			],
			[ '%s', '%d' ]
		);
	}


	/**
	 * 移除所有連動 $course_id 的商品 post_meta
	 * 刪除課程時，刪除連動商品的 post_meta
	 *
	 * @param int $course_id 課程 id
	 */
	private static function delete_bind_course_data_by_course_id( int $course_id ): void {
		$product_ids = \get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'meta_key'       => 'bind_course_ids',
				'meta_value'     => (string) $course_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
			);

		foreach ($product_ids as $product_id) {
			$int_product_id = (int) $product_id;
			\delete_post_meta($int_product_id, 'bind_course_id', $course_id);
			$bind_courses_data = \get_post_meta($int_product_id, 'bind_courses_data', true) ?: [];
			if (is_array($bind_courses_data)) {
				$bind_courses_data = array_filter($bind_courses_data, fn( $item ) => is_array($item) && ( (int) $item['id'] ) !== $course_id);
			}
			\update_post_meta($int_product_id, 'bind_courses_data', $bind_courses_data);
		}
	}

	/**
	 * 還原課程與相關項目
	 * 還原課程時連帶還原子章節以及組合商品(bundle product)
	 *
	 * @param int     $id 課程 id
	 * @param ?string $previous_status 前一個狀態
	 * @return array<int> 還原的 post id
	 */
	public static function untrash_course_and_related_items( int $id, ?string $previous_status = null ): array {
		$post_type = \get_post_type( $id );
		$is_course = \get_post_meta( $id, '_is_course', true ) === 'yes';
		if ( !$is_course && $post_type !== ChapterCPT::POST_TYPE ) {
			return [];
		}

		$chapter_ids = (array) CourseUtils::get_all_chapters( $id, true, [ 'any', 'trash' ] );
		$bundle_ids  = Helper::get_bundle_products(  $id, true, [ 'any', 'trash' ] );

		$restored_post_ids = [];

		foreach ([ ...$chapter_ids, ...$bundle_ids ] as $post_id) {
			/** @var int|\WP_Post|\WC_Product $post_id */
			$int_post_id = is_object($post_id) ? ( $post_id instanceof \WC_Product ? $post_id->get_id() : (int) $post_id->ID ) : (int) $post_id;
			$post_status = \get_post_status( $int_post_id );
			\wp_update_post(
				[
					'ID'          => $int_post_id,
					'post_status' => $post_status === 'trash' ? 'publish' : ( is_string($post_status) ? $post_status : 'publish' ),
				]
			);
			$restored_post_ids[] = $int_post_id;
		}

		return $restored_post_ids;
	}

	/**
	 * 課程開課
	 *
	 * @return void
	 */
	public static function register_course_launch(): void {

		// 找出沒有寄信(course_launch_action_done !== yes)，且現在時間 timestamp > 開課時間的課程 course_schedule
		global $wpdb;

		$course_ids = $wpdb->get_col(
		$wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'course_launch_action_done' JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'course_schedule' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (pm1.meta_value IS NULL OR pm1.meta_value != 'yes') AND pm2.meta_value < %d AND pm2.meta_value > 0",
		time()
		)
		);

		foreach ($course_ids as $course_id) {
			// 找出能上課程的用戶
			$user_ids = \get_users(
				[
					'fields'     => 'ID',
					'meta_key'   => 'avl_course_ids',
					'meta_value' => $course_id,
				]
				);

			foreach ($user_ids as $user_id) {
				\do_action(self::COURSE_LAUNCHED_ACTION, (int) $user_id, (int) $course_id);
			}

			// 註記已經執行過開課動作了，但不代表寄信
			\update_post_meta($course_id, 'course_launch_action_done', 'yes');
		}
	}

	/**
	 * 更新開課時間，清除寄信註記
	 * 更新前，已確認是 update 才會觸發( create 不會觸發)
	 *
	 * @param \WC_Product          $product 課程
	 * @param array<string, mixed> $meta_data 更新資料
	 */
	public static function clear_course_launch_action_done( \WC_Product $product, array $meta_data ): void {

		if ( ! isset( $meta_data['course_schedule'] ) ) {
			return;
		}

		$product_id          = $product->get_id();
		$old_course_schedule = $product->get_meta('course_schedule');
		$new_course_schedule = $meta_data['course_schedule'];

		if ($old_course_schedule !== $new_course_schedule) {
			// 要清除註記
			\delete_post_meta($product_id, 'course_launch_action_done');
		}
	}

	/**
	 * 如果儲存時，editor 是 power-editor，則要清除 elementor 相關資料
	 *
	 * @param \WC_Product          $product 課程
	 * @param array<string, mixed> $meta_data 更新資料
	 */
	public static function delete_elementor_data( \WC_Product $product, array $meta_data ): void {

		if ( ! isset( $meta_data['editor'] ) ) {
			return;
		}

		$product_id = $product->get_id();
		$editor     = $meta_data['editor'];

		if ( $editor === 'power-editor' ) {
			$post_meta = \get_post_meta(    $product_id );
			if (is_array($post_meta)) {
				foreach ( $post_meta as $key => $value ) {
					if ( strpos( (string) $key, '_elementor_' ) !== false ) {
						\delete_post_meta(  $product_id, (string) $key );
					}
				}
			}
		}
	}

	/**
	 * 移除學員後，寫入 log
	 *
	 * @param int $user_id 用戶 id
	 * @param int $course_id 課程 id
	 * @return void
	 * @throws \Exception 移除學員失敗
	 */
	public static function save_meta_remove_student( int $user_id, int $course_id ): void {
		$all_success = true;
		$success1    = \delete_user_meta( $user_id, 'avl_course_ids', $course_id );
		// 移除上課權限時，也把 avl_course_meta 相關資料刪除
		$success2 = AVLCourseMeta::delete( (int) $course_id, (int) $user_id );
		// 清除章節播放進度
		ChapterProgressService::delete_all_for_user_in_course( $user_id, $course_id );

		if (false === $success1 || false === $success2) {
			$all_success = false;
		}

		$crud = StudentLogCRUD::instance();
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => $all_success
					? sprintf(
						/* translators: %d: 課程 ID */
						esc_html__( 'Admin manually revoked course #%d access successfully', 'power-course' ),
						$course_id
					)
					: sprintf(
						/* translators: %d: 課程 ID */
						esc_html__( 'Admin manually revoked course #%d access failed', 'power-course' ),
						$course_id
					),
				'content'   => '',
				'log_type'  => AtHelper::COURSE_REMOVED,
			]
			);

		if (!$all_success) {
			throw new \Exception( esc_html__( 'Failed to remove student', 'power-course' ) );
		}
	}

	/**
	 * 更新寄信註記
	 * 移除學員後，將課程以後權的發信改為 mark_as_sent 改成 0
	 *
	 * @param int $user_id 用戶 id
	 * @param int $course_id 課程 id
	 */
	public static function update_email_mark_as_sent( int $user_id, int $course_id ): void {
		$where = [
			'user_id'    => (string) $user_id,
			'post_id'    => (string) $course_id,
			'trigger_at' => 'course_granted',
		];

		$data = [
			'mark_as_sent' => '0',
		];

		EmailRecord::update($where, $data);
	}

	/**
	 * 更新學員觀看課程期限時，寫入 log
	 *
	 * @param int $user_id 用戶 id
	 * @param int $course_id 課程 id
	 * @param int $timestamp 觀看時間
	 * @return void
	 * @throws \Exception 更新學員觀看課程期限失敗
	 */
	public static function save_meta_update_student( int $user_id, int $course_id, int $timestamp ): void {
		$success     = AVLCourseMeta::update( (int) $course_id, (int) $user_id, 'expire_date', $timestamp );
		$expire_date = new ExpireDate($timestamp);
		$crud        = StudentLogCRUD::instance();
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => $success
					? sprintf(
						/* translators: 1: 課程 ID, 2: 到期日 */
						esc_html__( 'Admin manually updated course #%1$d expire date to %2$s successfully', 'power-course' ),
						$course_id,
						$expire_date->expire_date_label
					)
					: sprintf(
						/* translators: 1: 課程 ID, 2: 到期日 */
						esc_html__( 'Admin manually updated course #%1$d expire date to %2$s failed', 'power-course' ),
						$course_id,
						$expire_date->expire_date_label
					),
				'content'   => '',
				'log_type'  => AtHelper::UPDATE_STUDENT,
			]
			);

		if (!$success) {
			throw new \Exception( esc_html__( 'Failed to update student course expire date', 'power-course' ) );
		}
	}

	/**
	 * 課程完成時註記
	 *
	 * @param int $course_id 課程 id
	 * @param int $user_id 用戶 id
	 * @return void
	 */
	public static function save_finished_time( int $course_id, int $user_id ): void {
		$success = AVLCourseMeta::update( (int) $course_id, (int) $user_id, 'finished_at', \wp_date('Y-m-d H:i:s') );

		$crud = StudentLogCRUD::instance();
		$crud->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => $success
					? sprintf(
						/* translators: %d: 課程 ID */
						esc_html__( 'Course #%d completed', 'power-course' ),
						$course_id
					)
					: sprintf(
						/* translators: %d: 課程 ID */
						esc_html__( 'Course #%d failed to complete', 'power-course' ),
						$course_id
					),
				'content'   => '',
				'log_type'  => AtHelper::COURSE_FINISHED,
			]
			);
	}
}
