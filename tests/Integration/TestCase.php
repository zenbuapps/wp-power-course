<?php
/**
 * 整合測試基礎類別
 * 所有 Power Course 整合測試必須繼承此類別
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;

/**
 * Class TestCase
 * 整合測試基礎類別，提供共用 helper methods
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * 最後發生的錯誤（用於驗證操作是否失敗）
	 *
	 * @var \Throwable|null
	 */
	protected ?\Throwable $lastError = null;

	/**
	 * 查詢結果（用於驗證 Query 操作的回傳值）
	 *
	 * @var mixed
	 */
	protected mixed $queryResult = null;

	/**
	 * ID 映射表（用戶名稱 → 用戶 ID 等）
	 *
	 * @var array<string, int>
	 */
	protected array $ids = [];

	/**
	 * Repository 容器
	 *
	 * @var \stdClass
	 */
	protected \stdClass $repos;

	/**
	 * Service 容器
	 *
	 * @var \stdClass
	 */
	protected \stdClass $services;

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->lastError   = null;
		$this->queryResult = null;
		$this->ids         = [];
		$this->repos       = new \stdClass();
		$this->services    = new \stdClass();

		// 確保自訂資料表存在
		$this->ensure_tables_exist();

		$this->configure_dependencies();
	}

	/**
	 * 清理（每個測試後執行）
	 * WP_UnitTestCase 會自動回滾資料庫事務，但自訂表需要手動清理
	 */
	public function tear_down(): void {
		$this->clean_custom_tables();
		parent::tear_down();
	}

	/**
	 * 初始化依賴（子類別必須實作）
	 * 在此方法中初始化 $this->repos 和 $this->services
	 */
	abstract protected function configure_dependencies(): void;

	// ========== 自訂資料表管理 ==========

	/**
	 * 確保自訂資料表存在
	 */
	protected function ensure_tables_exist(): void {
		static $tables_created = false;
		if ( $tables_created ) {
			return;
		}

		require_once dirname( __DIR__, 2 ) . '/inc/classes/AbstractTable.php';
		\J7\PowerCourse\AbstractTable::create_course_table();
		\J7\PowerCourse\AbstractTable::create_chapter_table();
		\J7\PowerCourse\AbstractTable::create_email_records_table();
		\J7\PowerCourse\AbstractTable::create_student_logs_table();
		\J7\PowerCourse\AbstractTable::create_chapter_progress_table();

		$tables_created = true;
	}

	/**
	 * 清理自訂資料表（每個測試後）
	 * WP_UnitTestCase 不會自動清理自訂表，需要手動清除
	 */
	protected function clean_custom_tables(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Plugin::COURSE_TABLE_NAME );   // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Plugin::CHAPTER_TABLE_NAME );  // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Plugin::EMAIL_RECORDS_TABLE_NAME ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Plugin::STUDENT_LOGS_TABLE_NAME ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Plugin::CHAPTER_PROGRESS_TABLE_NAME ); // phpcs:ignore
	}

	// ========== 資料建立 Helper ==========

	/**
	 * 建立測試課程（WooCommerce Product with _is_course = yes）
	 *
	 * @param array<string, mixed> $args 覆蓋預設值
	 * @return int 課程（商品）ID
	 */
	protected function create_course( array $args = [] ): int {
		$defaults = [
			'post_title'  => '測試課程',
			'post_status' => 'publish',
			'post_type'   => 'product',
		];

		$post_args = wp_parse_args( $args, $defaults );
		$course_id = $this->factory()->post->create( $post_args );

		// 設定為課程商品
		update_post_meta( $course_id, '_is_course', $args['_is_course'] ?? 'yes' );
		update_post_meta( $course_id, '_price', $args['price'] ?? '0' );
		update_post_meta( $course_id, '_regular_price', $args['price'] ?? '0' );
		update_post_meta( $course_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );

		if ( isset( $args['limit_value'] ) ) {
			update_post_meta( $course_id, 'limit_value', $args['limit_value'] );
		}
		if ( isset( $args['limit_unit'] ) ) {
			update_post_meta( $course_id, 'limit_unit', $args['limit_unit'] );
		}

		return $course_id;
	}

	/**
	 * 建立測試章節（pc_chapter post type）
	 *
	 * @param int                  $course_id 所屬課程 ID
	 * @param array<string, mixed> $args 覆蓋預設值
	 * @return int 章節 ID
	 */
	protected function create_chapter( int $course_id, array $args = [] ): int {
		$defaults = [
			'post_title'  => '測試章節',
			'post_status' => 'publish',
			'post_type'   => 'pc_chapter',
			'post_parent' => $course_id,
		];

		$post_args  = wp_parse_args( $args, $defaults );
		$chapter_id = $this->factory()->post->create( $post_args );

		// 設定章節所屬課程 meta
		update_post_meta( $chapter_id, 'parent_course_id', $course_id );

		return $chapter_id;
	}

	/**
	 * 將學員加入課程（直接操作資料庫，不透過 Service）
	 *
	 * @param int        $user_id    學員 ID
	 * @param int        $course_id  課程 ID
	 * @param int|string $expire_date 到期日（0 = 永久；timestamp；subscription_xxx）
	 */
	protected function enroll_user_to_course( int $user_id, int $course_id, int|string $expire_date = 0 ): void {
		// 新增 avl_course_ids user meta
		add_user_meta( $user_id, 'avl_course_ids', $course_id, false );

		// 新增 coursemeta expire_date
		AVLCourseMeta::update( $course_id, $user_id, 'expire_date', $expire_date );

		// 新增 course_granted_at
		AVLCourseMeta::update( $course_id, $user_id, 'course_granted_at', wp_date( 'Y-m-d H:i:s' ) );
	}

	/**
	 * 設定章節完成狀態
	 *
	 * @param int    $chapter_id  章節 ID
	 * @param int    $user_id     用戶 ID
	 * @param string $finished_at 完成時間（格式：Y-m-d H:i:s）
	 */
	protected function set_chapter_finished( int $chapter_id, int $user_id, string $finished_at ): void {
		AVLChapterMeta::update( $chapter_id, $user_id, 'finished_at', $finished_at );
	}

	/**
	 * 取得課程 meta 值
	 *
	 * @param int    $course_id 課程 ID
	 * @param int    $user_id   用戶 ID
	 * @param string $meta_key  meta key
	 * @return mixed
	 */
	protected function get_course_meta( int $course_id, int $user_id, string $meta_key ): mixed {
		return AVLCourseMeta::get( $course_id, $user_id, $meta_key, true );
	}

	/**
	 * 取得章節 meta 值
	 *
	 * @param int    $chapter_id 章節 ID
	 * @param int    $user_id    用戶 ID
	 * @param string $meta_key   meta key
	 * @return mixed
	 */
	protected function get_chapter_meta( int $chapter_id, int $user_id, string $meta_key ): mixed {
		return AVLChapterMeta::get( $chapter_id, $user_id, $meta_key, true );
	}

	/**
	 * 確認用戶是否有課程存取權
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 * @return bool
	 */
	protected function user_has_course_access( int $user_id, int $course_id ): bool {
		$avl_course_ids = get_user_meta( $user_id, 'avl_course_ids' );
		return in_array( (string) $course_id, array_map( 'strval', (array) $avl_course_ids ), true )
			|| in_array( $course_id, (array) $avl_course_ids, true );
	}

	// ========== 斷言 Helper ==========

	/**
	 * 斷言操作成功（$this->lastError 應為 null）
	 */
	protected function assert_operation_succeeded(): void {
		$this->assertNull(
			$this->lastError,
			sprintf( '預期操作成功，但發生錯誤：%s', $this->lastError?->getMessage() )
		);
	}

	/**
	 * 斷言操作失敗（$this->lastError 不應為 null）
	 */
	protected function assert_operation_failed(): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗，但沒有發生錯誤' );
	}

	/**
	 * 斷言操作失敗且錯誤類型符合
	 *
	 * @param string $type 例外類型的短類名（不含命名空間）
	 */
	protected function assert_operation_failed_with_type( string $type ): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗' );
		$actualType = ( new \ReflectionClass( $this->lastError ) )->getShortName();
		$this->assertSame( $type, $actualType, "錯誤類型不符，期望 {$type}，實際為 {$actualType}" );
	}

	/**
	 * 斷言操作失敗且錯誤訊息包含指定文字
	 *
	 * @param string $msg 期望錯誤訊息包含的文字
	 */
	protected function assert_operation_failed_with_message( string $msg ): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗' );
		$this->assertStringContainsString(
			$msg,
			$this->lastError->getMessage(),
			"錯誤訊息不包含 \"{$msg}\"，實際訊息：{$this->lastError->getMessage()}"
		);
	}

	/**
	 * 斷言 action hook 被觸發
	 *
	 * @param string $action_name action 名稱
	 */
	protected function assert_action_fired( string $action_name ): void {
		$this->assertGreaterThan(
			0,
			did_action( $action_name ),
			"Action '{$action_name}' 未被觸發"
		);
	}

	/**
	 * 斷言用戶擁有課程存取權
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 */
	protected function assert_user_has_course_access( int $user_id, int $course_id ): void {
		$this->assertTrue(
			$this->user_has_course_access( $user_id, $course_id ),
			"用戶 {$user_id} 應有課程 {$course_id} 的存取權，但實際上沒有"
		);
	}

	/**
	 * 斷言用戶沒有課程存取權
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 */
	protected function assert_user_has_no_course_access( int $user_id, int $course_id ): void {
		$this->assertFalse(
			$this->user_has_course_access( $user_id, $course_id ),
			"用戶 {$user_id} 不應有課程 {$course_id} 的存取權，但實際上有"
		);
	}
}
