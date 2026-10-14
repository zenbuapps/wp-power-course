<?php
/**
 * 刪除作業 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/assignment/刪除作業.feature
 * 依據：計劃 §9 P2、§4 DEC-01/06、§4.1、specs/api/api.yml（DELETE /assignments/{id}）
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class DeleteAssignmentTest
 * 對應 刪除作業.feature 全部 Example
 *
 * @group assignment
 * @group issue-131
 */
class DeleteAssignmentTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Alice） */
	private int $alice_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，publish） */
	private int $assignment_400_id;

	/** @var int 作業 402（已刪除練習，trash） */
	private int $assignment_402_id;

	/**
	 * 建立 Background：用戶、課程 100、章節 201、作業 400/402
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_da_' . \uniqid(),
				'user_email' => 'admin_da_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_da_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_da_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id     = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習一：變數宣告',
				'allow_text' => 'yes',
				'allow_file' => 'yes',
			]
		);
		$this->assignment_402_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'       => '已刪除練習',
				'post_status' => 'trash',
			]
		);
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員刪除作業時操作失敗
	 */
	public function test_非管理員刪除作業時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'DELETE', '/power-course/assignments/' . $this->assignment_400_id );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_not_deletable_assignments
	 *
	 * Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在
	 * Scenario Outline: 刪除<情境>的作業時操作失敗
	 *
	 * @param string $scenario 情境名稱（僅供測試名稱可讀性）
	 */
	public function test_刪除情境的作業時操作失敗( string $scenario ): void {
		\wp_set_current_user( $this->admin_id );

		$assignment_id = ( '不存在' === $scenario ) ? 9999 : $this->assignment_402_id;

		$response = $this->dispatch( 'DELETE', '/power-course/assignments/' . $assignment_id );

		$this->assert_operation_failed_response( $response, '作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_not_deletable_assignments(): array {
		return [
			'不存在'     => [ '不存在' ],
			'已在垃圾桶' => [ '已在垃圾桶' ],
		];
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 作業移至垃圾桶
	 * Example: 刪除作業後 post_status 為 trash
	 */
	public function test_刪除作業後post_status為trash(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'DELETE', '/power-course/assignments/' . $this->assignment_400_id );

		$this->assert_success_response( $response, 204 );
		$this->assert_assignment_fields( $this->assignment_400_id, [ 'post_status' => 'trash' ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 移至垃圾桶時保留該作業的繳交紀錄與討論訊息
	 * Example: 刪除作業後學員繳交與討論訊息仍存在
	 */
	public function test_刪除作業後學員繳交與討論訊息仍存在(): void {
		\wp_set_current_user( $this->admin_id );

		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'attachment_ids' => [],
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);
		$message_id = $this->seed_message(
			[
				'submission_id' => $submission_id,
				'sender_type'   => 'student',
				'sender_id'     => $this->alice_id,
				'body'          => '老師請問型別',
				'created_at'    => '2026-09-16 09:10:00',
			]
		);

		$response = $this->dispatch( 'DELETE', '/power-course/assignments/' . $this->assignment_400_id );

		$this->assert_success_response( $response, 204 );
		$this->assert_submission_exists(
			$submission_id,
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'status'        => 'pending',
			]
		);
		$this->assert_message_exists( $message_id );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 作業被永久刪除時刪除其繳交與討論訊息，附件紀錄與檔案保留
	 * Example: 永久刪除作業後繳交與訊息被清除但附件仍在
	 */
	public function test_永久刪除作業後繳交與訊息被清除但附件仍在(): void {
		$attachment_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '作業截圖.png',
				'mime_type'     => 'image/png',
				'file_size'     => 20480,
			]
		);
		$submission_id = $this->seed_submission(
			[
				'assignment_id'  => $this->assignment_402_id,
				'user_id'        => $this->alice_id,
				'text_body'      => '如附件',
				'attachment_ids' => [ $attachment_id ],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);
		$message_id = $this->seed_message(
			[
				'submission_id' => $submission_id,
				'sender_type'   => 'student',
				'sender_id'     => $this->alice_id,
				'body'          => '老師請問型別',
				'created_at'    => '2026-09-16 09:10:00',
			]
		);

		\wp_delete_post( $this->assignment_402_id, true );

		$this->assert_assignment_not_exists( $this->assignment_402_id );
		$this->assert_submission_not_exists( $submission_id );
		$this->assert_message_not_exists( $message_id );
		$this->assert_attachment_exists( $attachment_id );
	}
}
