<?php
/**
 * 章節 CPT 結構與層級整合測試
 *
 * Feature: specs/features/chapter/章節CPT結構與層級.feature
 * 測試 pc_chapter CPT 的結構、父子關係、排序與刪除行為：
 * - pc_chapter 為 hierarchical CPT
 * - post_parent 指向父章節或課程 ID
 * - 排序依 menu_order ASC、ID ASC、date ASC
 * - wp_trash_post 遞迴刪除子章節
 * - pc_avl_chaptermeta 記錄學員進度
 *
 * @group chapter
 * @group cpt
 * @group structure
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;

/**
 * Class ChapterCPTStructureTest
 * 測試 pc_chapter CPT 結構與階層管理
 */
class ChapterCPTStructureTest extends TestCase {

	/** @var int 課程 100 ID */
	private int $course_id;

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int Alice 學員 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress post API 與 AVLChapterMeta
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_cpt_' . uniqid(),
				'user_email' => 'admin_cpt_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_cpt_' . uniqid(),
				'user_email' => 'alice_cpt_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->course_id = $this->create_course(
			[ 'post_title' => 'PHP 基礎課', '_is_course' => 'yes' ]
		);

		$this->ids['Admin']  = $this->admin_id;
		$this->ids['Alice']  = $this->alice_id;
		$this->ids['Course'] = $this->course_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * pc_chapter CPT 已正確註冊（post type 存在）
	 */
	public function test_冒煙_pc_chapter_CPT已註冊(): void {
		$this->assertTrue( post_type_exists( 'pc_chapter' ), 'pc_chapter CPT 應已在 WordPress 中註冊' );
	}

	/**
	 * @test
	 * @group smoke
	 * pc_chapter 為 hierarchical
	 */
	public function test_冒煙_pc_chapter為hierarchical(): void {
		$post_type_obj = get_post_type_object( 'pc_chapter' );
		$this->assertNotNull( $post_type_obj, 'pc_chapter post type 物件應存在' );
		$this->assertTrue( $post_type_obj->hierarchical, 'pc_chapter 應為 hierarchical（支援父子關係）' );
	}

	// ========== CPT 註冊參數 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: pc_chapter 支援 page-attributes（啟用 menu_order）
	 */
	public function test_pc_chapter支援page_attributes(): void {
		$supports = get_all_post_type_supports( 'pc_chapter' );
		$this->assertArrayHasKey( 'page-attributes', $supports, 'pc_chapter 應支援 page-attributes（menu_order）' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_chapter 支援 title
	 */
	public function test_pc_chapter支援title(): void {
		$this->assertTrue( post_type_supports( 'pc_chapter', 'title' ), 'pc_chapter 應支援 title' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_chapter 支援 custom-fields
	 */
	public function test_pc_chapter支援custom_fields(): void {
		$this->assertTrue( post_type_supports( 'pc_chapter', 'custom-fields' ), 'pc_chapter 應支援 custom-fields' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_chapter show_in_rest=true
	 */
	public function test_pc_chapter_show_in_rest為true(): void {
		$post_type_obj = get_post_type_object( 'pc_chapter' );
		$this->assertTrue( $post_type_obj->show_in_rest, 'pc_chapter 應開放 REST API' );
	}

	// ========== 父子關係 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 頂層章節的 post_parent 為 0，靠 parent_course_id meta 關聯課程
	 *
	 * 設計依據：inc/classes/Resources/Chapter/Utils/Utils.php:222
	 *   「如果 depth 是 0，代表是頂層，不使用 post_parent，而是用 meta_key parent_course_id」
	 *   「post_parent 要清空」
	 */
	public function test_頂層章節的post_parent為0(): void {
		$chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );

		$chapter = get_post( $chapter_id );

		$this->assertSame( 0, (int) $chapter->post_parent, '頂層章節的 post_parent 應為 0（新架構靠 parent_course_id meta 關聯）' );
		$this->assertSame(
			$this->course_id,
			(int) get_post_meta( $chapter_id, 'parent_course_id', true ),
			'頂層章節的 parent_course_id meta 應指向課程 ID'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 二層章節的 post_parent 指向父章節 ID
	 */
	public function test_子章節的post_parent指向父章節(): void {
		$parent_chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$child_chapter_id  = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '1-1 小節',
				'post_parent' => $parent_chapter_id,
			]
		);

		$child_chapter = get_post( $child_chapter_id );
		$this->assertSame( $parent_chapter_id, (int) $child_chapter->post_parent, '子章節的 post_parent 應指向父章節' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: parent_course_id meta 正確設定為頂層課程 ID
	 */
	public function test_章節的parent_course_id_meta正確(): void {
		$chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );

		$parent_course_id = get_post_meta( $chapter_id, 'parent_course_id', true );
		$this->assertEquals( $this->course_id, (int) $parent_course_id, 'parent_course_id meta 應為課程 ID' );
	}

	// ========== 排序（menu_order）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 章節可設定 menu_order，WP query 依 menu_order ASC 排序
	 */
	public function test_章節可設定menu_order(): void {
		// 建立 3 個章節，不同 menu_order
		$ch_id_1 = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '章節A', 'menu_order' => 1 ]
		);
		$ch_id_0a = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '章節B', 'menu_order' => 0 ]
		);
		$ch_id_0b = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '章節C', 'menu_order' => 0 ]
		);

		// 查詢：menu_order ASC, ID ASC
		// 新架構：頂層章節 post_parent=0，靠 parent_course_id meta 關聯課程
		$query = new \WP_Query(
			[
				'post_type'      => 'pc_chapter',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'parent_course_id',
				'meta_value'     => $this->course_id,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
			]
		);

		$result_ids = $query->posts;

		// menu_order=0 的應在前（依 ID ASC：ch_id_0a < ch_id_0b）
		// menu_order=1 的應在後
		$this->assertEquals( $ch_id_0a, $result_ids[0] ?? null, '最小 ID 且 menu_order=0 應排第一' );
		$this->assertEquals( $ch_id_0b, $result_ids[1] ?? null, '第二小 ID 且 menu_order=0 應排第二' );
		$this->assertEquals( $ch_id_1, $result_ids[2] ?? null, 'menu_order=1 應排第三' );
	}

	// ========== 刪除行為 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: wp_trash_post 刪除父章節時，子章節同步被 trash
	 */
	public function test_刪除父章節_子章節同步被trash(): void {
		$parent_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$child1_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '1-1 小節', 'post_parent' => $parent_id ]
		);
		$child2_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '1-2 小節', 'post_parent' => $parent_id ]
		);

		// 刪除父章節
		wp_trash_post( $parent_id );

		// 驗證：父章節被 trash
		$this->assertSame( 'trash', get_post_status( $parent_id ), '父章節應被 trash' );

		// WordPress 遞迴 trash 子 posts
		// 注意：WordPress 的 wp_trash_post 行為可能因版本而異
		// 依 spec：子章節同步被 trash（WordPress 預設行為）
		$child1_status = get_post_status( $child1_id );
		$child2_status = get_post_status( $child2_id );

		// 確認子章節也已被 trash（WordPress hierarchical 行為）
		$this->assertSame( 'trash', $child1_status, '子章節 1-1 應被 trash（WordPress hierarchical 行為）' );
		$this->assertSame( 'trash', $child2_status, '子章節 1-2 應被 trash（WordPress hierarchical 行為）' );
	}

	// ========== 學員進度存儲 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 章節進度寫入 pc_avl_chaptermeta，使用 finished_at meta key
	 */
	public function test_章節完成時間寫入chaptermeta(): void {
		$chapter_id  = $this->create_chapter( $this->course_id, [ 'post_title' => '測試章節' ] );
		$finished_at = '2026-04-13 10:30:00';

		// 模擬完成章節
		AVLChapterMeta::update( $chapter_id, $this->alice_id, 'finished_at', $finished_at );

		// 讀取並驗證
		$stored = AVLChapterMeta::get( $chapter_id, $this->alice_id, 'finished_at', true );
		$this->assertSame( $finished_at, $stored, 'finished_at 應正確儲存到 pc_avl_chaptermeta' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: first_visit_at 記錄學員首次進入章節的時間
	 */
	public function test_首次進入章節時間寫入chaptermeta(): void {
		$chapter_id     = $this->create_chapter( $this->course_id, [ 'post_title' => '測試章節' ] );
		$first_visit_at = wp_date( 'Y-m-d H:i:s' );

		AVLChapterMeta::update( $chapter_id, $this->alice_id, 'first_visit_at', $first_visit_at );

		$stored = AVLChapterMeta::get( $chapter_id, $this->alice_id, 'first_visit_at', true );
		$this->assertSame( $first_visit_at, $stored, 'first_visit_at 應正確儲存' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 不同學員的章節進度相互獨立
	 */
	public function test_不同學員章節進度相互獨立(): void {
		$bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_cpt_' . uniqid(),
				'user_email' => 'bob_cpt_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$chapter_id   = $this->create_chapter( $this->course_id, [ 'post_title' => '測試章節' ] );
		$alice_finish = '2026-04-13 09:00:00';
		$bob_finish   = '2026-04-13 11:00:00';

		AVLChapterMeta::update( $chapter_id, $this->alice_id, 'finished_at', $alice_finish );
		AVLChapterMeta::update( $chapter_id, $bob_id, 'finished_at', $bob_finish );

		$alice_stored = AVLChapterMeta::get( $chapter_id, $this->alice_id, 'finished_at', true );
		$bob_stored   = AVLChapterMeta::get( $chapter_id, $bob_id, 'finished_at', true );

		$this->assertSame( $alice_finish, $alice_stored, 'Alice 的章節進度應獨立' );
		$this->assertSame( $bob_finish, $bob_stored, 'Bob 的章節進度應獨立' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 建立 3 層巢狀章節，parent_course_id meta 均為頂層課程 ID
	 * （meta 是手動設定，不是遞迴計算的）
	 */
	public function test_三層巢狀章節_parent_course_id(): void {
		$level1_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第一章' ]
		);

		$level2_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '1-1 節', 'post_parent' => $level1_id ]
		);
		// 手動設定 parent_course_id（正常應由 LifeCycle 或 API 設定）
		update_post_meta( $level2_id, 'parent_course_id', $this->course_id );

		$level3_id = $this->factory()->post->create(
			[
				'post_title'  => '1-1-1 小節',
				'post_type'   => 'pc_chapter',
				'post_status' => 'publish',
				'post_parent' => $level2_id,
			]
		);
		update_post_meta( $level3_id, 'parent_course_id', $this->course_id );

		// 確認三層的 parent_course_id 均為頂層課程
		$l1_course_id = get_post_meta( $level1_id, 'parent_course_id', true );
		$l2_course_id = get_post_meta( $level2_id, 'parent_course_id', true );
		$l3_course_id = get_post_meta( $level3_id, 'parent_course_id', true );

		$this->assertEquals( $this->course_id, (int) $l1_course_id );
		$this->assertEquals( $this->course_id, (int) $l2_course_id );
		$this->assertEquals( $this->course_id, (int) $l3_course_id );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 章節標題含 XSS 輸入，wp_insert_post 的 sanitize 行為
	 */
	public function test_章節標題含XSS_WordPress_sanitize(): void {
		$xss_title  = '<script>alert("xss")</script>章節標題';
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => $xss_title ]
		);

		$chapter = get_post( $chapter_id );
		// WordPress wp_insert_post 在儲存前會對 post_title 做 sanitize
		// 結果為 strip_tags 過後的字串
		$this->assertStringNotContainsString( '<script>', $chapter->post_title, '標題不應包含 <script> 標籤' );
	}
}
