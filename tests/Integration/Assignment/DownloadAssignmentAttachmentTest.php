<?php
/**
 * 下載作業附件 整合測試（Issue #131 第一波 P3）
 *
 * Feature: specs/features/assignment/下載作業附件.feature
 * 依據：計劃 §9 P3、§6.2、§4 DEC-08/DEC-09、§12 R-04/R-07/R-14/R-16、
 *       specs/api/api.yml（GET /assignment-attachments/{id}/download）、
 *       specs/entity/erm.dbml（pc_assignment_attachments Note）
 *
 * ─── 本層「能驗什麼、不能驗什麼」───────────────────────────────
 * DEC-08：下載 callback 回傳「帶標頭的 WP_REST_Response」（Content-Type、Content-Disposition、
 * X-Content-Type-Options 都掛在 response 物件上，不是用 PHP header() 送），所以這三個標頭可以
 * 從 rest_get_server()->dispatch() 拿到的 response 直接斷言。
 *
 * 但 Cache-Control（來自 \nocache_headers()）是用 PHP header() 送出，PHPUnit CLI 下
 * headers_sent() 已為 true，第一行就 return，$response->get_headers() 永遠看不到它
 * （見 ChapterApiNocacheHeadersTest 的說明）。因此本檔對「回應標頭 Cache-Control 應包含
 * no-cache」這條 Rule，改用 token_get_all() 靜態檢查 callback 第一個語句是否為
 * \nocache_headers()，真實標頭交給 tests/e2e 驗證（R-07）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class DownloadAssignmentAttachmentTest
 *
 * @group assignment
 * @group issue-131
 */
class DownloadAssignmentAttachmentTest extends AssignmentTestCase {

	/** Assignment Api 類別 FQCN（下載端點掛在管理端 Core\Api，登入即可，callback 內分角色） */
	private const ASSIGNMENT_API_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\Api';

	/** 下載端點 callback method 名稱（ApiBase 命名慣例推導，見 AssignmentApiContractTest） */
	private const DOWNLOAD_CALLBACK = 'get_assignment_attachments_with_id_download_callback';

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int Alice（subscriber） */
	private int $alice_id;

	/** @var int Bob（subscriber） */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_id;

	/** @var int 作業 401（練習二：上傳截圖） */
	private int $assignment_id;

	/** @var int 附件 700（Alice 上傳，綁定在 Alice 的繳交中） */
	private int $attachment_700_id;

	/** @var int 附件 701（Bob 上傳，綁定在 Bob 的繳交中） */
	private int $attachment_701_id;

	/** @var int 附件 702（Alice 上傳，未綁定任何繳交） */
	private int $attachment_702_id;

	/**
	 * 建立 Background：管理員、Alice、Bob、課程 100、章節 201、作業 401、
	 * 附件 700/701/702、Alice 與 Bob 各自的繳交
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_daa_' . \uniqid(),
				'user_email' => 'admin_daa_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_daa_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_daa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_daa_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_daa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id  = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );
		$this->assignment_id = $this->create_assignment(
			$this->chapter_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
			]
		);

		$this->attachment_700_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '作業截圖.png',
				'mime_type'     => 'image/png',
				'file_size'     => 20480,
			]
		);
		$this->attachment_701_id = $this->seed_attachment(
			[
				'user_id'       => $this->bob_id,
				'original_name' => 'Bob 報告.pdf',
				'mime_type'     => 'application/pdf',
				'file_size'     => 40960,
			]
		);
		$this->attachment_702_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '草稿.docx',
				'mime_type'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'file_size'     => 8192,
			]
		);

		// Alice 與 Bob 各自的繳交，702 刻意不綁定任何繳交
		$this->seed_submission(
			[
				'assignment_id'  => $this->assignment_id,
				'user_id'        => $this->alice_id,
				'text_body'      => '如附件',
				'attachment_ids' => [ $this->attachment_700_id ],
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);
		$this->seed_submission(
			[
				'assignment_id'  => $this->assignment_id,
				'user_id'        => $this->bob_id,
				'text_body'      => '如附件',
				'attachment_ids' => [ $this->attachment_701_id ],
				'submitted_at'   => '2026-09-16 10:00:00',
			]
		);
	}

	/**
	 * 下載 route 的路徑
	 *
	 * @param int $attachment_id 附件 ID
	 * @return string
	 */
	private function download_route( int $attachment_id ): string {
		return '/power-course/assignment-attachments/' . $attachment_id . '/download';
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客下載附件時操作失敗
	 */
	public function test_未登入訪客下載附件時操作失敗(): void {
		\wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_700_id ) );

		$this->assert_not_logged_in( $response );
	}

	// ========== 前置（狀態）- 附件紀錄與實體檔案都必須存在 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 附件紀錄與實體檔案都必須存在
	 * Example: 附件紀錄不存在時操作失敗
	 */
	public function test_附件紀錄不存在時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', $this->download_route( 9999 ) );

		$this->assert_operation_failed_response( $response, '附件不存在', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 附件紀錄與實體檔案都必須存在
	 * Example: 實體檔案已不存在時操作失敗
	 */
	public function test_實體檔案已不存在時操作失敗(): void {
		$missing_file_attachment_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '已遺失檔案.png',
				'mime_type'     => 'image/png',
				'file_size'     => 1024,
			],
			false // 刻意不建立實體檔案
		);

		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', $this->download_route( $missing_file_attachment_id ) );

		$this->assert_operation_failed_response( $response, '附件不存在', 404, 'rest_not_found' );
	}

	// ========== 前置（狀態）- 學員只能下載自己上傳的附件 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 學員只能下載自己上傳的附件
	 * Example: Alice 下載 Bob 的附件時操作失敗
	 */
	public function test_Alice下載Bob的附件時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_701_id ) );

		$this->assert_operation_failed_response( $response, '無權存取此附件', 403, 'rest_forbidden' );
	}

	// ========== 前置（狀態）- 管理員只能下載已出現在任一學員繳交中的附件 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 管理員只能下載已出現在任一學員繳交中的附件
	 * Example: 管理員下載尚未被繳交使用的附件時操作失敗
	 */
	public function test_管理員下載尚未被繳交使用的附件時操作失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_702_id ) );

		$this->assert_operation_failed_response( $response, '找不到對應的作業附件', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * R-16：JSON 欄位裡找附件的 LIKE 邊界。刻意讓附件 id 為 700 與 7000，
	 * 7000 被繳交綁定、700 未被綁定；若實作用天真的
	 * `LIKE '%"attachment_id":700%'`（缺結尾逗號防呆），會誤判 700 命中 7000 的那筆繳交。
	 * 管理員下載未被綁定的 700 應仍然失敗（找不到對應的作業附件），不能被 7000 誤中。
	 *
	 * 以 seed_attachment() 的明確 id 參數（P3 擴充）指定 700 / 7000，
	 * 不用 ALTER TABLE ... AUTO_INCREMENT（DDL 會在 InnoDB 造成隱性 COMMIT，
	 * 破壞 WP_UnitTestCase 的交易回滾隔離，污染後續測試）。
	 */
	public function test_管理員下載附件700不會被7000的LIKE誤中(): void {
		$attachment_700 = $this->seed_attachment(
			[
				'id'            => 700,
				'user_id'       => $this->alice_id,
				'original_name' => '未綁定700.png',
				'mime_type'     => 'image/png',
				'file_size'     => 1024,
			]
		);

		$attachment_7000 = $this->seed_attachment(
			[
				'id'            => 7000,
				'user_id'       => $this->alice_id,
				'original_name' => '已綁定7000.png',
				'mime_type'     => 'image/png',
				'file_size'     => 1024,
			]
		);

		// Alice 對 $this->assignment_id 已有 set_up() 建立的繳交（唯一鍵 assignment_id+user_id），
		// 這裡另建一個作業承載「綁定 7000」的繳交，避免違反 uq_assignment_submissions_owner
		$other_assignment_id = $this->create_assignment( $this->chapter_id, [ 'title' => '練習三：另一份作業' ] );
		$this->seed_submission(
			[
				'assignment_id'  => $other_assignment_id,
				'user_id'        => $this->alice_id,
				'text_body'      => '如附件',
				'attachment_ids' => [ $attachment_7000 ],
				'submitted_at'   => '2026-09-16 11:00:00',
			]
		);

		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', $this->download_route( $attachment_700 ) );

		$this->assert_operation_failed_response( $response, '找不到對應的作業附件', 404, 'rest_not_found' );
	}

	// ========== 後置（回應）- 驗權通過後由伺服器串流檔案，以原始檔名下載 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 驗權通過後由伺服器串流檔案，以原始檔名下載
	 * Example: Alice 下載自己已繳交的附件
	 */
	public function test_Alice下載自己已繳交的附件(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_700_id ) );

		$this->assert_stream_response( $response, '作業截圖.png', 'image/png' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 學員亦可下載自己尚未被任何繳交使用的附件（歸屬判定 = 上傳者本人）
	 * Example: Alice 下載自己尚未繳交使用的附件
	 */
	public function test_Alice下載自己尚未繳交使用的附件(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_702_id ) );

		$this->assert_stream_response( $response, '草稿.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 管理員下載已出現在任一繳交中的附件
	 * Example: 管理員下載 Bob 繳交中的附件
	 */
	public function test_管理員下載Bob繳交中的附件(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', $this->download_route( $this->attachment_701_id ) );

		$this->assert_stream_response( $response, 'Bob 報告.pdf', 'application/pdf' );
	}

	/**
	 * 斷言下載成功回應（狀態碼、Content-Disposition 含原檔名、Content-Type、X-Content-Type-Options）
	 * DEC-08：Content-Disposition 格式為 attachment; filename*=UTF-8''{rawurlencode(原檔名)}
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @param string            $original_name 期望的原始檔名
	 * @param string            $mime_type 期望的 MIME 類型
	 */
	private function assert_stream_response( \WP_REST_Response $response, string $original_name, string $mime_type ): void {
		$this->assert_success_response( $response );

		$headers = $response->get_headers();

		$content_disposition = (string) ( $headers['Content-Disposition'] ?? '' );
		$this->assertStringContainsString( 'attachment', $content_disposition, 'Content-Disposition 應為 attachment（強制下載）' );
		$this->assertStringContainsString(
			\rawurlencode( $original_name ),
			$content_disposition,
			\sprintf( 'Content-Disposition 應含原始檔名的 UTF-8 編碼（DEC-08），實際：%s', $content_disposition )
		);

		$this->assertSame( $mime_type, $headers['Content-Type'] ?? null, 'Content-Type 不符' );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] ?? null, 'X-Content-Type-Options 應為 nosniff' );
	}

	// ========== 後置（回應）- 回應帶禁止快取標頭 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回應帶禁止快取標頭
	 * Example: 下載回應禁止快取
	 * R-07：Cache-Control 來自 \nocache_headers()，PHPUnit CLI 下無 runtime 觀測點，
	 * 改用靜態檢查 callback 第一個語句（真實標頭由 E2E 驗證）
	 */
	public function test_下載回應禁止快取(): void {
		$this->assert_callback_starts_with_nocache_headers_for( self::ASSIGNMENT_API_CLASS, self::DOWNLOAD_CALLBACK );
	}
}
