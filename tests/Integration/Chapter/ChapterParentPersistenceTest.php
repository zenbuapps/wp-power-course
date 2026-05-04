<?php
/**
 * 子章節 post_parent 持久化整合測試（Issue #216 Bug #2 端到端驗證）
 *
 * Feature: specs/features/chapter/更新章節.feature
 * Rule: 後置（狀態）- 編輯子章節時 post_parent 必須保留，不得被重置為 0
 *
 * 本測試驗證：
 * - 拖曳成為子章節後，立刻 wp_update_post 編輯標題，post_parent 仍為 parent ID
 * - 三層結構編輯子節點後父子關係完整保留
 * - 同一個 session 內排序 + 編輯不會因 stale cache 被重置
 *
 * 根因說明：sort_chapters 用 raw $wpdb->query() 寫入 post_parent 後，
 * 若不主動清除 object cache，後續 wp_get_post_parent_id() 會回傳舊值（0），
 * 導致 wp_insert_post_data filter 在編輯子章節時誤判 parent 不存在而清空 post_parent。
 *
 * @group chapter
 * @group update
 * @group issue-216
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class ChapterParentPersistenceTest
 * 驗證 sort + update 同一 session 內 post_parent 的持久性
 */
class ChapterParentPersistenceTest extends TestCase {

	/** @var int 課程 100 ID */
	private int $course_id;

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
	}

	/**
	 * 每個測試前建立基本資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_persist_' . uniqid(),
				'user_email' => 'admin_persist_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );

		$this->course_id = $this->create_course(
			[ 'post_title' => 'PHP 基礎課', '_is_course' => 'yes' ]
		);
	}

	// ========== 二層結構編輯子章節 post_parent 持久 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Rule: 子章節編輯標題後 post_parent 持久存在
	 * Spec: specs/features/chapter/更新章節.feature line 57-65
	 */
	public function test_edit_child_chapter_preserves_post_parent_two_levels(): void {
		$parent_200 = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$child_201  = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 小節' ] );

		// Step 1: 透過 sort 把 child_201 拖到 parent_200 之下
		$from_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_201, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_201, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $parent_200 ],
		];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// Step 2: 立刻 wp_update_post 編輯 child_201 標題
		$update_result = \wp_update_post(
			[
				'ID'         => $child_201,
				'post_title' => '1-1 小節（更新）',
			],
			true
		);
		$this->assertNotInstanceOf( \WP_Error::class, $update_result, 'wp_update_post 不應失敗' );

		// Step 3: 驗證 post_parent 仍為 parent_200（從 DB 直接讀，繞過 cache）
		global $wpdb;
		$db_parent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
				$child_201
			)
		);
		$this->assertSame(
			$parent_200,
			$db_parent,
			'編輯子章節標題後，DB 中 post_parent 必須仍為父章節 ID（不可被重置為 0）'
		);

		// 同時驗證標題確實有更新
		$child = \get_post( $child_201 );
		$this->assertSame( '1-1 小節（更新）', $child->post_title, '標題應已更新' );
	}

	// ========== 三層結構編輯子節點父子關係保留 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Rule: 三層結構編輯子節點後父子關係完整保留
	 * Spec: specs/features/chapter/更新章節.feature line 67-77
	 */
	public function test_edit_child_chapter_preserves_post_parent_three_levels(): void {
		$top_200    = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$middle_201 = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 小節' ] );
		$leaf_301   = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1-1 子節' ] );

		// 透過 sort 一次建立三層
		$from_tree = [
			[ 'id' => $top_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $middle_201, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
			[ 'id' => $leaf_301, 'depth' => 0, 'menu_order' => 2, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $top_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $middle_201, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $top_200 ],
			[ 'id' => $leaf_301, 'depth' => 2, 'menu_order' => 0, 'parent_id' => $middle_201 ],
		];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// 編輯 leaf_301 的 post_title
		\wp_update_post(
			[
				'ID'         => $leaf_301,
				'post_title' => '1-1-1 子節（編輯）',
			],
			true
		);

		// DB 直查驗證
		global $wpdb;
		$leaf_parent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $leaf_301 )
		);
		$middle_parent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $middle_201 )
		);

		$this->assertSame( $middle_201, $leaf_parent, '章節 301 的 post_parent 應為 201（middle）' );
		$this->assertSame( $top_200, $middle_parent, '章節 201 的 post_parent 應為 200（top），整體結構保留' );
	}

	// ========== 排序後立刻編輯（不經 reload）父子關係正確 ==========

	/**
	 * @test
	 * @group edge
	 * @group issue-216
	 *
	 * Rule: 排序後立刻編輯（不經 reload）父子關係仍正確
	 * Spec: specs/features/chapter/更新章節.feature line 79-85
	 */
	public function test_sort_then_immediately_edit_in_same_session(): void {
		$parent_200 = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$child_202  = $this->create_chapter( $this->course_id, [ 'post_title' => '第三章' ] );

		// 預熱 cache（讓 child_202 的 post_parent = 0 進入 object cache）
		\get_post( $child_202 );
		\wp_get_post_parent_id( $child_202 );

		// 排序：child_202 拖到 parent_200 之下
		$from_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_202, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_202, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $parent_200 ],
		];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// 不做任何 reload / cache flush，立即編輯
		\wp_update_post(
			[
				'ID'         => $child_202,
				'post_title' => '第三章（同 session 編輯）',
			],
			true
		);

		// DB 直查確認 post_parent 沒被重置
		global $wpdb;
		$final_parent = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $child_202 )
		);
		$this->assertSame(
			$parent_200,
			$final_parent,
			'同一 session 內排序後立刻編輯，post_parent 不應被 stale cache 影響重置為 0'
		);
	}
}
