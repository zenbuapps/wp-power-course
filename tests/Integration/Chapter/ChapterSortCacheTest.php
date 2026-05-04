<?php
/**
 * 章節排序 Cache 失效整合測試（Issue #216 Bug #2 根因驗證）
 *
 * Feature: specs/features/chapter/排序章節.feature
 * Rule: 後置（Cache）- raw SQL 更新後必須對每個被異動的章節呼叫 clean_post_cache
 *
 * 本測試驗證：
 * - sort_chapters() 在 COMMIT 後對所有 batch 異動的 post_id 呼叫 clean_post_cache
 * - clean_post_cache action 觸發次數 = 異動章節數
 * - 排序後立即呼叫 wp_get_post_parent_id 必須拿到新 parent，而非 stale 值
 * - 大量章節分批排序時每一批都要清除 cache（覆蓋 batch_size = 50 邊界）
 * - 三層巢狀結構排序後 cache 一致
 *
 * @group chapter
 * @group sort
 * @group cache
 * @group issue-216
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class ChapterSortCacheTest
 * 驗證 sort_chapters() 對 WP object cache 的失效行為
 */
class ChapterSortCacheTest extends TestCase {

	/** @var int 課程 100 ID */
	private int $course_id;

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 收集 clean_post_cache action 觸發時的 post_id 列表（每個測試初始化）
	 *
	 * @var int[]
	 */
	private array $cleaned_post_ids = [];

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 ChapterUtils::sort_chapters
	}

	/**
	 * 每個測試前建立基本資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_sortcache_' . uniqid(),
				'user_email' => 'admin_sortcache_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->course_id = $this->create_course(
			[ 'post_title' => 'PHP 基礎課', '_is_course' => 'yes' ]
		);

		$this->ids['Admin']  = $this->admin_id;
		$this->ids['Course'] = $this->course_id;

		$this->cleaned_post_ids = [];
		// 掛 clean_post_cache action 收集所有被清 cache 的 post_id
		add_action(
			'clean_post_cache',
			function ( $post_id ): void {
				$this->cleaned_post_ids[] = (int) $post_id;
			}
		);
	}

	// ========== 規格驗證：clean_post_cache 行為 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Rule: 後置（Cache）- raw SQL 更新後必須對每個被異動的章節呼叫 clean_post_cache
	 * Spec: specs/features/chapter/排序章節.feature line 67-73
	 *
	 * 驗證：sort_chapters 排序三個章節後，每個被異動的 post_id 至少觸發一次 clean_post_cache
	 */
	public function test_sort_chapters_calls_clean_post_cache_for_each_updated_id(): void {
		// 建立 3 個頂層章節
		$chapter_200 = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$chapter_201 = $this->create_chapter( $this->course_id, [ 'post_title' => '第二章' ] );
		$chapter_202 = $this->create_chapter( $this->course_id, [ 'post_title' => '第三章' ] );

		// 預熱 cache（讀過一次，模擬實際使用情境）
		\get_post( $chapter_200 );
		\get_post( $chapter_201 );
		\get_post( $chapter_202 );

		// 建立 from_tree 與 to_tree
		$from_tree = [
			[ 'id' => $chapter_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_201, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_202, 'depth' => 0, 'menu_order' => 2, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $chapter_202, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_200, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_201, 'depth' => 0, 'menu_order' => 2, 'parent_id' => $this->course_id ],
		];

		// 重置收集器（忽略 set_up 階段的無關 cache 清除）
		$this->cleaned_post_ids = [];

		$result = ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		$this->assertTrue( $result, 'sort_chapters 應回傳 true' );

		// 三個 post_id 都應出現在被清 cache 的列表中
		$this->assertContains( $chapter_200, $this->cleaned_post_ids, '章節 200 應已清除 cache' );
		$this->assertContains( $chapter_201, $this->cleaned_post_ids, '章節 201 應已清除 cache' );
		$this->assertContains( $chapter_202, $this->cleaned_post_ids, '章節 202 應已清除 cache' );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/排序章節.feature line 73
	 * 驗證：clean_post_cache action 觸發次數至少等於異動章節數（讓下游 hook 能同步收到通知）
	 */
	public function test_clean_post_cache_action_fired_for_each_id(): void {
		$chapter_a = $this->create_chapter( $this->course_id, [ 'post_title' => 'A' ] );
		$chapter_b = $this->create_chapter( $this->course_id, [ 'post_title' => 'B' ] );

		$from_tree = [
			[ 'id' => $chapter_a, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_b, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $chapter_b, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $chapter_a, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
		];

		$this->cleaned_post_ids = [];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// 計算 chapter_a / chapter_b 在收集器中至少各出現一次
		$count_a = count( array_filter( $this->cleaned_post_ids, fn( $id ) => $id === $chapter_a ) );
		$count_b = count( array_filter( $this->cleaned_post_ids, fn( $id ) => $id === $chapter_b ) );

		$this->assertGreaterThanOrEqual( 1, $count_a, '章節 A 至少應觸發一次 clean_post_cache action' );
		$this->assertGreaterThanOrEqual( 1, $count_b, '章節 B 至少應觸發一次 clean_post_cache action' );
	}

	// ========== 規格驗證：排序後立即讀取 parent 必須拿到新值 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Rule: 排序後立刻讀取 parent 必須拿到最新值
	 * Spec: specs/features/chapter/排序章節.feature line 67-72
	 *
	 * 驗證：將子章節 202 拖入父章節 200 之下後，
	 * 立即呼叫 wp_get_post_parent_id(202) 必須回傳 200，不可為 stale 的舊值
	 */
	public function test_post_parent_returned_immediately_after_sort(): void {
		$parent_200 = $this->create_chapter( $this->course_id, [ 'post_title' => '父章節' ] );
		$child_202  = $this->create_chapter( $this->course_id, [ 'post_title' => '原本頂層的章節' ] );

		// 預熱 cache，讓 child_202 的 post_parent = 0 進入 object cache
		\get_post( $child_202 );
		$this->assertSame( 0, \wp_get_post_parent_id( $child_202 ), '排序前 child_202 的 post_parent 應為 0（頂層）' );

		// 拖曳 child_202 到 parent_200 之下
		$from_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_202, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
		];
		$to_tree = [
			[ 'id' => $parent_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $child_202, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $parent_200 ],
		];

		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// 立刻呼叫 wp_get_post_parent_id（會走 object cache）必須拿到新值
		$parent_id_after_sort = \wp_get_post_parent_id( $child_202 );
		$this->assertSame(
			$parent_200,
			$parent_id_after_sort,
			'排序後 wp_get_post_parent_id 必須回傳新 parent，不可為 stale 的 0'
		);
	}

	// ========== 規格驗證：分批排序 cache 都要清除 ==========

	/**
	 * @test
	 * @group edge
	 * @group issue-216
	 *
	 * Rule: 大量章節分批排序時每一批都要清除 cache
	 * Spec: specs/features/chapter/排序章節.feature line 75-79
	 *
	 * 驗證：超過 batch_size = 50 的章節（60 個）排序後，全部 60 個都要被清 cache
	 */
	public function test_batch_size_60_chapters_all_caches_cleared(): void {
		$chapter_ids = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$chapter_ids[] = $this->create_chapter(
				$this->course_id,
				[ 'post_title' => '章節' . $i ]
			);
		}

		$from_tree = [];
		foreach ( $chapter_ids as $idx => $cid ) {
			$from_tree[] = [
				'id'         => $cid,
				'depth'      => 0,
				'menu_order' => $idx,
				'parent_id'  => $this->course_id,
			];
		}
		// to_tree 反向排序，讓所有 menu_order 都異動
		$to_tree = [];
		foreach ( array_reverse( $chapter_ids ) as $idx => $cid ) {
			$to_tree[] = [
				'id'         => $cid,
				'depth'      => 0,
				'menu_order' => $idx,
				'parent_id'  => $this->course_id,
			];
		}

		$this->cleaned_post_ids = [];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		foreach ( $chapter_ids as $cid ) {
			$this->assertContains(
				$cid,
				$this->cleaned_post_ids,
				sprintf( '60 個章節中，章節 ID %d 應已被清 cache', $cid )
			);
		}
	}

	// ========== 規格驗證：三層巢狀結構 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Rule: 後置（巢狀）- 排序與 cache 失效須覆蓋全部 5 層巢狀
	 * Spec: specs/features/chapter/排序章節.feature line 99-109
	 *
	 * 驗證：三層結構排序後 cache 一致，wp_get_post_parent_id 拿到新值
	 */
	public function test_three_level_nested_clean_post_cache_propagates(): void {
		$top_200    = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$middle_201 = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '1-1 小節', 'post_parent' => $top_200 ]
		);
		$leaf_301 = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '1-1-1 子節', 'post_parent' => $middle_201 ]
		);

		// 預熱 cache
		\get_post( $leaf_301 );

		// 將 leaf_301 直接拖到 top_200 之下（從第三層提升到第二層）
		$from_tree = [
			[ 'id' => $top_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $middle_201, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $top_200 ],
			[ 'id' => $leaf_301, 'depth' => 2, 'menu_order' => 0, 'parent_id' => $middle_201 ],
		];
		$to_tree = [
			[ 'id' => $top_200, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
			[ 'id' => $middle_201, 'depth' => 1, 'menu_order' => 0, 'parent_id' => $top_200 ],
			[ 'id' => $leaf_301, 'depth' => 1, 'menu_order' => 1, 'parent_id' => $top_200 ],
		];

		$this->cleaned_post_ids = [];
		ChapterUtils::sort_chapters( [ 'from_tree' => $from_tree, 'to_tree' => $to_tree ] );

		// leaf_301 應已被清 cache
		$this->assertContains( $leaf_301, $this->cleaned_post_ids, '深度三的 leaf_301 應已被清 cache' );

		// 立刻讀 leaf_301 的 parent，應為 top_200（不是 stale 的 middle_201）
		$this->assertSame(
			$top_200,
			\wp_get_post_parent_id( $leaf_301 ),
			'三層結構排序後 leaf_301 的 parent 應為 top_200'
		);
	}
}
