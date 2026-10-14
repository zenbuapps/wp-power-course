<?php
/**
 * 複製課程不複製作業 整合測試（Issue #131 第一波 P2，回歸守門）
 *
 * Feature: specs/features/course/複製課程.feature
 * Rule: 後置（狀態）- 章節下的作業不複製到新課程
 *
 * 依據：計劃 §9 P2、§6.5「複製課程 ── Utils\Duplicate 的 allowed_post_types 不含
 * pc_assignment → 不複製（回歸測試守住）」、§12 R-12
 *
 * ─── 為什麼本測試預期在 Green 之前就綠（回歸守門）──────────────
 * inc/classes/Utils/Duplicate.php::duplicate_children_post() 與
 * duplicate_children_chapter() 的 $allowed_post_types 白名單本來就只有
 * ['post', 'product', pc_chapter, pc_email]，兩處 get_children() 查詢皆以
 * post_type 過濾，作業（post_type=pc_assignment）從未被複製邏輯撿到——
 * 這件事與 Assignment CPT 是否已註冊、是否已有 Core\Api 無關，純粹是
 * Duplicate.php 既有白名單沒有把新 CPT 加進去。
 * 本測試只用裸 wp_insert_post( post_type => 'pc_assignment' ) 建立作業（不依賴
 * Core\CPT 是否存在），是釘住現有白名單不被誤改的回歸測試，不是本階段要
 * 新增的行為；若未來有人「順手」把 pc_assignment 加進白名單，本測試會由綠轉紅。
 * ──────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Course\Service\Crud as CourseCrud;

/**
 * Class CourseDuplicateExcludesAssignmentsTest
 *
 * @group assignment
 * @group issue-131
 */
class CourseDuplicateExcludesAssignmentsTest extends AssignmentTestCase {

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: course/複製課程.feature
	 * Rule: 後置（狀態）- 章節下的作業不複製到新課程
	 * Example: 複製含作業的課程後新課程沒有作業
	 */
	public function test_複製含作業的課程後新課程沒有作業(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_210_id = $this->create_chapter( $course_id, [ 'post_title' => '1-1 小節' ] );
		$assignment_id  = $this->create_assignment( $chapter_210_id, [ 'title' => '練習一：變數宣告' ] );

		$result = CourseCrud::duplicate( $course_id );

		$this->assertIsArray( $result, '複製課程應成功並回傳陣列（回傳 WP_Error 代表複製失敗）' );
		$new_course_id = (int) ( $result['id'] ?? 0 );
		$this->assertGreaterThan( 0, $new_course_id, '複製後應取得新課程 ID' );

		/** @var array<int, int> $all_assignment_ids */
		$all_assignment_ids = \get_posts(
			[
				'post_type'      => self::ASSIGNMENT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		$this->assertSame(
			[ $assignment_id ],
			\array_map( 'intval', $all_assignment_ids ),
			'複製課程後，全站作業仍應只有原本那一份，新課程不應有任何作業'
		);

		// 原作業本身不受複製流程影響
		$this->assert_assignment_fields(
			$assignment_id,
			[
				'post_parent'      => $chapter_210_id,
				'parent_course_id' => $course_id,
			]
		);
	}
}
