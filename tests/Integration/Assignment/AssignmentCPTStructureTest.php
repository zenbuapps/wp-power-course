<?php
/**
 * 作業 CPT 結構測試（Issue #131 第一波 P2）
 *
 * 無直接對應的 .feature（CPT 註冊細節屬於實作決策），依據：
 *   - specs/plan/issue-131-assignment-grading/implementation-plan.md DEC-05、R-05、R-12
 *   - specs/entity/erm.dbml TABLE: assignments（post_type="pc_assignment"）
 *
 * ─── 本層「能驗什麼、不能驗什麼」────────────────────────────
 * R-12 的兩支回歸測試（get_flatten_post_ids / get_all_chapters 不受影響）刻意用
 * 裸 wp_insert_post( post_type => 'pc_assignment' ) 建立作業，不依賴 Core\CPT
 * 是否已註冊——ChapterUtils::get_flatten_post_ids() 與 CourseUtils::get_all_chapters()
 * 的查詢本來就用 post_type=pc_chapter 過濾，作業從未混入，預期在 Green 之前就綠
 * （回歸守門，見回報）。其餘 CPT 屬性測試（show_in_rest / public / show_in_menu 等）
 * 則必須等 Core\CPT::register_cpt() 實作後才會轉綠——找不到該類別時以 $this->fail()
 * 產生 Failure，不讓整批測試因 ReflectionException / Class not found fatal。
 * ──────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class AssignmentCPTStructureTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentCPTStructureTest extends AssignmentTestCase {

	/** Assignment CPT 類別 FQCN（計劃 §5.1，不含前導反斜線，供動態 ::CONST 解析用） */
	private const ASSIGNMENT_CPT_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\CPT';

	/**
	 * 取得 Assignment CPT 的 post_type 常數值
	 * 類別不存在時以 $this->fail() 產生 Failure（不是 fatal），紅燈原因單一乾淨
	 *
	 * @return string
	 */
	private function assignment_post_type(): string {
		$class = self::ASSIGNMENT_CPT_CLASS;
		if ( ! \class_exists( $class ) ) {
			$this->fail( "{$class} 尚未實作，CPT 結構無法檢查" );
		}

		/** @var string $post_type */
		$post_type = $class::POST_TYPE;

		return $post_type;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_assignment CPT 應已在 WordPress 中註冊
	 */
	public function test_pc_assignment_CPT已註冊(): void {
		$post_type = $this->assignment_post_type();
		$this->assertTrue( \post_type_exists( $post_type ), 'pc_assignment CPT 應已在 WordPress 中註冊' );
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：CPT::POST_TYPE 常數值應為 'pc_assignment'（erm.dbml TABLE: assignments）
	 */
	public function test_POST_TYPE常數值正確(): void {
		$this->assertSame( 'pc_assignment', $this->assignment_post_type(), "CPT::POST_TYPE 應為 'pc_assignment'" );
	}

	// ========== 快樂路徑（Happy）：DEC-05 CPT 註冊參數 ==========

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 應為非公開（DEC-05：public=false）
	 */
	public function test_public為false(): void {
		$obj = \get_post_type_object( $this->assignment_post_type() );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertFalse( $obj->public, 'pc_assignment CPT 應為非公開（public=false）' );
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 不應可被公開查詢（DEC-05：publicly_queryable=false）
	 */
	public function test_publicly_queryable為false(): void {
		$obj = \get_post_type_object( $this->assignment_post_type() );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertFalse( $obj->publicly_queryable, 'pc_assignment CPT 應為 publicly_queryable=false' );
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 應排除在搜尋之外（DEC-05：exclude_from_search=true）
	 */
	public function test_exclude_from_search為true(): void {
		$obj = \get_post_type_object( $this->assignment_post_type() );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertTrue( $obj->exclude_from_search, 'pc_assignment CPT 應排除在搜尋之外' );
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 應為非階層式（DEC-05：hierarchical=false）
	 */
	public function test_hierarchical為false(): void {
		$obj = \get_post_type_object( $this->assignment_post_type() );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertFalse( $obj->hierarchical, 'pc_assignment CPT 應為非階層式（hierarchical=false）' );
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT show_in_rest 應為 false（DEC-05、R-05：作業說明是付費內容，
	 * show_in_rest=true 會經 /wp/v2/pc_assignment 外洩）
	 */
	public function test_show_in_rest為false(): void {
		$obj = \get_post_type_object( $this->assignment_post_type() );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertFalse( $obj->show_in_rest, 'pc_assignment CPT show_in_rest 應為 false（R-05）' );
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 不得出現在 WP 後台左側選單（DEC-05：show_in_menu=false，
	 * 對齊 AccessPass CPT 的 Issue #255 慣例；即使本機開發環境（Plugin::$is_local=true）
	 * 也必須為 false，站長一律走 React SPA 管理）
	 */
	public function test_show_in_menu為false(): void {
		$class = self::ASSIGNMENT_CPT_CLASS;
		if ( ! \class_exists( $class ) || ! \method_exists( $class, 'register_cpt' ) ) {
			$this->fail( "{$class}::register_cpt() 尚未實作" );
		}

		$original_is_local = \J7\PowerCourse\Plugin::$is_local;

		try {
			\J7\PowerCourse\Plugin::$is_local = true;
			$class::register_cpt();

			$obj = \get_post_type_object( $this->assignment_post_type() );
			$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
			$this->assertFalse( $obj->show_in_menu, 'pc_assignment CPT 不應出現在 WP 後台左側選單，即使在本機開發環境' );
		} finally {
			\J7\PowerCourse\Plugin::$is_local = $original_is_local;
			$class::register_cpt();
		}
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_assignment CPT 應支援 title / editor / custom-fields / page-attributes（DEC-05）
	 */
	public function test_supports必要功能(): void {
		$post_type = $this->assignment_post_type();

		$this->assertTrue( \post_type_supports( $post_type, 'title' ), 'pc_assignment 應支援 title（作業標題）' );
		$this->assertTrue( \post_type_supports( $post_type, 'editor' ), 'pc_assignment 應支援 editor（作業說明）' );
		$this->assertTrue( \post_type_supports( $post_type, 'custom-fields' ), 'pc_assignment 應支援 custom-fields（allow_text 等 postmeta）' );
		$this->assertTrue( \post_type_supports( $post_type, 'page-attributes' ), 'pc_assignment 應支援 page-attributes（menu_order 排序）' );
	}

	// ========== 回歸守門（R-12）==========

	/**
	 * @test
	 * @group edge
	 *
	 * R-12：有作業時 ChapterUtils::get_flatten_post_ids() 的結果不變
	 * （該查詢以 post_type=pc_chapter 過濾，作業從未混入；可能實作前就綠，見回報）
	 */
	public function test_有作業時get_flatten_post_ids不受影響(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$course_id  = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_id = $this->create_chapter( $course_id, [ 'post_title' => '第一章' ] );

		$without_assignment = ChapterUtils::get_flatten_post_ids( $course_id );

		$this->create_assignment( $chapter_id, [ 'title' => '練習一：變數宣告' ] );

		$with_assignment = ChapterUtils::get_flatten_post_ids( $course_id );

		$this->assertSame(
			$without_assignment,
			$with_assignment,
			'新增作業不應改變 get_flatten_post_ids() 的結果（該查詢以 post_type=pc_chapter 過濾）'
		);
		$this->assertContains( $chapter_id, $with_assignment, '章節本身仍應在攤平清單中' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * R-12：有作業時 CourseUtils::get_all_chapters() 的結果不變
	 * （該查詢以 post_type=pc_chapter 過濾，作業從未混入；可能實作前就綠，見回報）
	 *
	 * get_all_chapters() 是向下相容舊資料的查詢，以 post_parent=course_id 找章節
	 * （對照 tests/Integration/Course/CourseCRUDTest.php::test_刪除課程後章節也被trash 的
	 * 註解），故此處明確設 post_parent 模擬舊資料結構，而非只靠 parent_course_id meta。
	 */
	public function test_有作業時get_all_chapters不受影響(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$course_id  = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_id = $this->create_chapter(
			$course_id,
			[
				'post_title'  => '第一章',
				'post_parent' => $course_id,
			]
		);

		$without_assignment = CourseUtils::get_all_chapters( $course_id, true );

		$this->create_assignment( $chapter_id, [ 'title' => '練習一：變數宣告' ] );

		$with_assignment = CourseUtils::get_all_chapters( $course_id, true );

		$this->assertSame(
			$without_assignment,
			$with_assignment,
			'新增作業不應改變 get_all_chapters() 的結果（該查詢以 post_type=pc_chapter 過濾）'
		);
		$this->assertContains( $chapter_id, $with_assignment, '章節本身仍應在清單中' );
	}
}
