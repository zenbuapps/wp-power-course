<?php
/**
 * 更新作業 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/assignment/更新作業.feature
 * 依據：計劃 §9 P2、§4 DEC-01/05/13/18、§4.1、specs/api/api.yml（PUT /assignments/{id}）
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class UpdateAssignmentTest
 * 對應 更新作業.feature 全部 Example
 *
 * @group assignment
 * @group issue-131
 */
class UpdateAssignmentTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Bob） */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告） */
	private int $assignment_400_id;

	/**
	 * 建立 Background：用戶、課程 100、章節 200/201、作業 400
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_ua_' . \uniqid(),
				'user_email' => 'admin_ua_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_ua_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_ua_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );

		$chapter_200_id       = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$this->chapter_201_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '1-1 變數',
				'post_parent' => $chapter_200_id,
			]
		);

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title' => '練習一：變數宣告',
			]
		);
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員更新作業時操作失敗
	 */
	public function test_非管理員更新作業時操作失敗(): void {
		\wp_set_current_user( $this->bob_id );

		$response = $this->dispatch( 'PUT', '/power-course/assignments/' . $this->assignment_400_id );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在
	 * Example: 作業不存在時更新失敗
	 */
	public function test_作業不存在時更新失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/9999',
			[ 'title' => '新標題' ]
		);

		$this->assert_operation_failed_response( $response, '作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在
	 * Example: 作業在垃圾桶中時更新失敗
	 */
	public function test_作業在垃圾桶中時更新失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$trashed_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'       => '已刪除練習',
				'post_status' => 'trash',
			]
		);

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $trashed_id,
			[ 'title' => '新標題' ]
		);

		$this->assert_operation_failed_response( $response, '作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 提供 chapter_id 時目標章節必須存在
	 * Example: 改掛到不存在的章節時更新失敗
	 */
	public function test_改掛到不存在的章節時更新失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[ 'chapter_id' => 9999 ]
		);

		$this->assert_operation_failed_response( $response, '章節不存在', 404, 'rest_not_found' );
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- 合併既有設定後 allow_text 與 allow_file 至少一個為 yes
	 * Example: 關閉唯一開啟的文字繳交時更新失敗
	 */
	public function test_關閉唯一開啟的文字繳交時更新失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[ 'allow_text' => 'no' ]
		);

		$this->assert_operation_failed_response( $response, '至少需允許文字或檔案其中一種繳交方式', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_invalid_fields
	 *
	 * Rule: 前置（參數）- 提供的欄位必須符合建立作業的格式規則
	 * Scenario Outline: <欄位> 格式不合法時更新失敗
	 *
	 * @param string $field 欄位名稱
	 * @param mixed  $value 不合法的值
	 * @param string $message 期望的 zh_TW 錯誤訊息
	 */
	public function test_欄位格式不合法時更新失敗( string $field, $value, string $message ): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[ $field => $value ]
		);

		$this->assert_operation_failed_response( $response, $message, 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public static function provide_invalid_fields(): array {
		return [
			'title'     => [ 'title', '', 'title 長度必須為 1 至 200 字' ],
			'max_files' => [ 'max_files', 21, 'max_files 必須為 1 至 20 的整數' ],
			'due_at'    => [ 'due_at', 123, 'due_at 必須為 0 或 10 位 Unix timestamp' ],
		];
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 只更新有提供的欄位，其餘維持原值
	 * Example: 只修改標題時題型設定不變
	 */
	public function test_只修改標題時題型設定不變(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[ 'title' => '練習一：變數與常數' ]
		);

		$this->assert_success_response( $response );
		$this->assert_assignment_fields(
			$this->assignment_400_id,
			[
				'post_parent'      => $this->chapter_201_id,
				'parent_course_id' => $this->course_id,
				'title'            => '練習一：變數與常數',
				'allow_text'       => 'yes',
				'allow_file'       => 'no',
				'max_files'        => 1,
				'due_at'           => 0,
				'menu_order'       => 0,
				'post_status'      => 'publish',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 改掛到其他課程的章節時，parent_course_id 更新為新章節的根課程
	 * Example: 將作業移到另一門課程的章節
	 */
	public function test_將作業移到另一門課程的章節(): void {
		\wp_set_current_user( $this->admin_id );

		$course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );
		$chapter_300_id = $this->create_chapter( $course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[ 'chapter_id' => $chapter_300_id ]
		);

		$this->assert_success_response( $response );
		$this->assert_assignment_fields(
			$this->assignment_400_id,
			[
				'post_parent'      => $chapter_300_id,
				'parent_course_id' => $course_101_id,
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 更新作業不影響既有的繳交紀錄
	 * Example: 開啟檔案繳交後既有通過的繳交維持通過
	 */
	public function test_開啟檔案繳交後既有通過的繳交維持通過(): void {
		\wp_set_current_user( $this->admin_id );

		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'let x = 1;',
				'attachment_ids' => [],
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);

		$response = $this->dispatch(
			'PUT',
			'/power-course/assignments/' . $this->assignment_400_id,
			[
				'allow_file'    => 'yes',
				'required_file' => 'yes',
			]
		);

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$submission_id,
			[
				'text_body' => 'let x = 1;',
				'status'    => 'pass',
				'feedback'  => '寫得很好',
				'graded_at' => '2026-09-16 10:00:00',
			]
		);
	}
}
