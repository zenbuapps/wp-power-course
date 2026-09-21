<?php
/**
 * 繳交作業 整合測試（Issue #131 第一波 P3）
 *
 * Feature: specs/features/assignment/繳交作業.feature
 * 依據：計劃 §9 P3、§6.1（驗證順序）、§4 DEC-01/DEC-02/DEC-12、§12 R-06/R-17、
 *       specs/api/api.yml（POST /my/assignments/{id}/submit）、specs/entity/erm.dbml（pc_assignment_submissions Note）
 *
 * ─── 介面契約備忘（詳見回報「介面契約」段）─────────────────────────
 * 「尚未報名此課程」與「此章節尚未解鎖，請先完成前面的章節」皆為 domain 前置狀態失敗，
 * status 403。error code 依 specs/api/api.yml 該端點的內嵌範例（notEnrolled / chapterLocked，
 * L7706、L7713）為字面字串 "403"，並與既有 inc/classes/Resources/Chapter/Core/Api.php
 * post_toggle_finish_chapters_with_id_callback() 對同一句鎖定訊息的既有寫法
 * （`'code' => '403'`）一致，故採 code = '403'（字面字串，不是 rest_forbidden）。
 * 「此章節尚未解鎖」訊息文字沿用既有 zh_TW 譯文（含句尾句號，D27），
 * 不是 feature 逐字的「...前面的章節」（無句號）——兩者差一個句號，見回報「疑義」段。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Plugin;

/**
 * Class SubmitAssignmentTest
 *
 * @group assignment
 * @group issue-131
 */
class SubmitAssignmentTest extends AssignmentTestCase {

	/** 既有 LinearViewing 鎖定訊息（沿用既有 zh_TW 譯文，含句尾句號，D27） */
	private const CHAPTER_LOCKED_MESSAGE = '此章節尚未解鎖，請先完成前面的章節。';

	/** @var int Alice（已加入課程 100） */
	private int $alice_id;

	/** @var int Bob（未加入課程） */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 章節 202（1-2 型別） */
	private int $chapter_202_id;

	/** @var int 作業 400（練習一：變數宣告，僅文字，章節 201） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，文字+必填檔案，章節 201，max_files=2） */
	private int $assignment_401_id;

	/** @var int 作業 402（練習三：心得，必填文字+選填檔案，章節 201） */
	private int $assignment_402_id;

	/** @var int 作業 403（練習四：只收檔案，章節 201） */
	private int $assignment_403_id;

	/** @var int 作業 405（練習五：型別轉換，僅文字，章節 202） */
	private int $assignment_405_id;

	/** @var int 附件 700（Alice 上傳） */
	private int $attachment_700_id;

	/** @var int 附件 701（Alice 上傳） */
	private int $attachment_701_id;

	/** @var int 附件 702（Alice 上傳） */
	private int $attachment_702_id;

	/** @var int 附件 710（Bob 上傳） */
	private int $attachment_710_id;

	/**
	 * 建立 Background：Alice/Bob、課程 100、章節 201/202、作業 400/401/402/403/405、
	 * 附件 700/701/702/710、Alice 已加入課程 100（expire_date 0）、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_sub_' . \uniqid(),
				'user_email' => 'alice_sub_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_sub_' . \uniqid(),
				'user_email' => 'bob_sub_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );
		$this->chapter_202_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-2 型別' ] );

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習一：變數宣告',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'menu_order' => 0,
			]
		);
		$this->assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => 1789747140,
				'menu_order'    => 10,
			]
		);
		$this->assignment_402_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習三：心得',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_text' => 'yes',
				'max_files'     => 1,
				'menu_order'    => 20,
			]
		);
		$this->assignment_403_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習四：只收檔案',
				'allow_text' => 'no',
				'allow_file' => 'yes',
				'max_files'  => 1,
				'menu_order' => 30,
			]
		);
		$this->assignment_405_id = $this->create_assignment(
			$this->chapter_202_id,
			[
				'title'      => '練習五：型別轉換',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'menu_order' => 0,
			]
		);

		$this->attachment_700_id = $this->seed_attachment( [ 'user_id' => $this->alice_id, 'original_name' => '作業截圖.png', 'mime_type' => 'image/png', 'file_size' => 20480 ] );
		$this->attachment_701_id = $this->seed_attachment( [ 'user_id' => $this->alice_id, 'original_name' => '執行結果.pdf', 'mime_type' => 'application/pdf', 'file_size' => 40960 ] );
		$this->attachment_702_id = $this->seed_attachment( [ 'user_id' => $this->alice_id, 'original_name' => '程式碼.zip', 'mime_type' => 'application/zip', 'file_size' => 1024 ] );
		$this->attachment_710_id = $this->seed_attachment( [ 'user_id' => $this->bob_id, 'original_name' => 'Bob 截圖.png', 'mime_type' => 'image/png', 'file_size' => 20480 ] );

		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$this->set_now( '2026-09-17 10:00:00' );
	}

	/**
	 * 繳交 route
	 *
	 * @param int $assignment_id 作業 ID
	 * @return string
	 */
	private function submit_route( int $assignment_id ): string {
		return '/power-course/my/assignments/' . $assignment_id . '/submit';
	}

	/**
	 * 以指定使用者身分繳交作業
	 *
	 * @param int         $user_id 使用者 ID（0 = 未登入）
	 * @param int         $assignment_id 作業 ID
	 * @param string|null $text_body 文字內容
	 * @param array<int>  $attachment_ids 附件 ID 陣列
	 * @return \WP_REST_Response
	 */
	private function submit_as( int $user_id, int $assignment_id, ?string $text_body, array $attachment_ids ): \WP_REST_Response {
		\wp_set_current_user( $user_id );

		return $this->dispatch(
			'POST',
			$this->submit_route( $assignment_id ),
			[],
			[
				'text_body'      => $text_body,
				'attachment_ids' => $attachment_ids,
			]
		);
	}

	/**
	 * 斷言「尚未報名此課程」403（DEC-12；code 見檔案頂端介面契約備忘）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	private function assert_not_enrolled( \WP_REST_Response $response ): void {
		$this->assert_operation_failed_response( $response, '尚未報名此課程', 403, '403' );
	}

	/**
	 * 查詢指定 (assignment_id, user_id) 的繳交筆數
	 *
	 * @param int $assignment_id 作業 ID
	 * @param int $user_id 使用者 ID
	 * @return int
	 */
	private function submission_count( int $assignment_id, int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE assignment_id = %d AND user_id = %d", $assignment_id, $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * 斷言指定繳交的 attachments JSON 內 attachment_id 依序符合期望
	 *
	 * @param int              $submission_id 繳交 ID
	 * @param array<int, int>  $expected_ids 期望的 attachment_id 依序清單
	 */
	private function assert_submission_attachment_ids( int $submission_id, array $expected_ids ): void {
		$row = $this->get_submission_row( $submission_id );
		$this->assertIsArray( $row, "作業繳交 {$submission_id} 應存在" );

		/** @var array<int, array{attachment_id: int}> $decoded */
		$decoded     = (array) \json_decode( (string) $row['attachments'], true );
		$actual_ids = \array_map(
			static fn( array $item ): int => (int) ( $item['attachment_id'] ?? 0 ),
			$decoded
		);

		$this->assertSame( $expected_ids, $actual_ids, '繳交的 attachment_id 清單不符' );
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客繳交時操作失敗
	 */
	public function test_未登入訪客繳交時操作失敗(): void {
		$response = $this->submit_as( 0, $this->assignment_400_id, 'let x = 1;', [] );

		$this->assert_not_logged_in( $response );
	}

	// ========== 前置（狀態）- 有效觀看權 / 作業存在 ==========

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_not_enrolled_scenarios
	 *
	 * Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權；作業不存在或在垃圾桶時
	 *       一律回應「尚未報名此課程」，不洩漏作業是否存在（DEC-12）
	 *
	 * @param string $scenario 情境名稱（僅供測試名稱可讀性）
	 */
	public function test_無有效觀看權或作業不存在時繳交失敗( string $scenario ): void {
		switch ( $scenario ) {
			case '未加入課程':
				$response = $this->submit_as( $this->bob_id, $this->assignment_400_id, 'let x = 1;', [] );
				break;

			case '已到期':
				// R-06：is_expired() 讀的是 time() 不是 Clock，用相對時間確保測試不隨系統時鐘漂移
				$this->enroll_user_to_course( $this->alice_id, $this->course_id, \time() - \DAY_IN_SECONDS );
				$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, 'let x = 1;', [] );
				break;

			case '未開課':
				// R-06：is_course_ready() 讀的是 time() 不是 Clock，用相對時間確保測試不隨系統時鐘漂移
				\update_post_meta( $this->course_id, 'course_schedule', \time() + \DAY_IN_SECONDS );
				$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, 'let x = 1;', [] );
				break;

			case '作業不存在':
				$response = $this->submit_as( $this->alice_id, 9999, 'let x = 1;', [] );
				break;

			case '作業在垃圾桶':
				$trashed_id = $this->create_assignment( $this->chapter_201_id, [ 'title' => '已刪除練習', 'post_status' => 'trash', 'menu_order' => 40 ] );
				$response   = $this->submit_as( $this->alice_id, $trashed_id, 'let x = 1;', [] );
				break;

			default:
				$this->fail( "未知情境：{$scenario}" );
		}

		$this->assert_not_enrolled( $response );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_not_enrolled_scenarios(): array {
		return [
			'未加入課程'   => [ '未加入課程' ],
			'已到期'       => [ '已到期' ],
			'未開課'       => [ '未開課' ],
			'作業不存在'   => [ '作業不存在' ],
			'作業在垃圾桶' => [ '作業在垃圾桶' ],
		];
	}

	// ========== 前置（狀態）- 線性觀看章節解鎖 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 課程啟用線性觀看時，作業所屬章節必須已對學員解鎖
	 * Example: 啟用線性觀看且作業所屬章節鎖定時繳交失敗（6a）
	 */
	public function test_啟用線性觀看且作業所屬章節鎖定時繳交失敗(): void {
		\update_post_meta( $this->course_id, 'enable_linear_viewing', 'yes' );
		// 章節 201 無 finished_at（Given 預設狀態，不需額外操作）→ 章節 202 對 Alice 鎖定

		$response = $this->submit_as( $this->alice_id, $this->assignment_405_id, '型別轉換心得', [] );

		$this->assert_operation_failed_response( $response, self::CHAPTER_LOCKED_MESSAGE, 403, '403' );
		$this->assertSame( 0, $this->submission_count( $this->assignment_405_id, $this->alice_id ), '鎖定時不應建立繳交紀錄' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 線性觀看解鎖後可繳交
	 * Example: 啟用線性觀看且完成前一章節後可繳交下一章節的作業（6b）
	 */
	public function test_啟用線性觀看且完成前一章節後可繳交下一章節的作業(): void {
		\update_post_meta( $this->course_id, 'enable_linear_viewing', 'yes' );
		$this->set_chapter_finished( $this->chapter_201_id, $this->alice_id, '2026-09-16 10:00:00' );

		$response = $this->submit_as( $this->alice_id, $this->assignment_405_id, '型別轉換心得', [] );

		$this->assert_success_response( $response );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 第一個章節永遠解鎖
	 * Example: 啟用線性觀看時第一個章節永遠解鎖，無完成紀錄也可繳交（6c）
	 */
	public function test_啟用線性觀看時第一個章節永遠解鎖無完成紀錄也可繳交(): void {
		\update_post_meta( $this->course_id, 'enable_linear_viewing', 'yes' );
		// 章節 201 無 finished_at（Given 預設狀態）

		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, 'let x = 1;', [] );

		$this->assert_success_response( $response );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 未啟用線性觀看時不檢查章節鎖定
	 * Example: 未啟用線性觀看時不檢查章節鎖定（6d）
	 */
	public function test_未啟用線性觀看時不檢查章節鎖定(): void {
		\update_post_meta( $this->course_id, 'enable_linear_viewing', 'no' );
		// 章節 201 無 finished_at

		$response = $this->submit_as( $this->alice_id, $this->assignment_405_id, '型別轉換心得', [] );

		$this->assert_success_response( $response );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 具 manage_woocommerce 能力者不受線性觀看鎖定限制（R-17：is_exempt 依 current_user_can）
	 * Example: 具 manage_woocommerce 能力者不受線性觀看鎖定限制（6e）
	 */
	public function test_具manage_woocommerce能力者不受線性觀看鎖定限制(): void {
		$manager_id = $this->factory()->user->create(
			[
				'user_login' => 'manager_sub_' . \uniqid(),
				'user_email' => 'manager_sub_' . \uniqid() . '@test.com',
				'role'       => 'shop_manager',
			]
		);
		$this->enroll_user_to_course( $manager_id, $this->course_id, 0 );
		\update_post_meta( $this->course_id, 'enable_linear_viewing', 'yes' );
		// 章節 201 對 Manager 無 finished_at

		$response = $this->submit_as( $manager_id, $this->assignment_405_id, '型別轉換心得', [] );

		$this->assert_success_response( $response );
	}

	// ========== 前置（參數）- 文字內容與附件至少一項 ==========

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_empty_content
	 *
	 * Rule: 前置（參數）- 文字內容（去除前後空白後）與附件至少提供一項
	 * Scenario Outline: 文字為<情境>且無附件時繳交失敗
	 *
	 * @param string $text_body 文字內容
	 */
	public function test_無內容時繳交失敗( string $text_body ): void {
		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, $text_body, [] );

		$this->assert_operation_failed_response( $response, '請至少填寫文字或附件', 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_empty_content(): array {
		return [
			'空字串'   => [ '' ],
			'只有空白' => [ '   ' ],
		];
	}

	// ========== 前置（參數）- 內容須符合題型設定與附件上限 ==========

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_shape_violations
	 *
	 * Rule: 前置（參數）- 提供的內容必須符合作業的題型設定與附件上限
	 *
	 * @param string      $scenario 情境名稱（僅供測試名稱可讀性）
	 * @param string|null $text_body 文字內容
	 * @param string      $expected_message 期望的錯誤訊息
	 */
	public function test_內容不符題型設定時繳交失敗( string $scenario, ?string $text_body, string $expected_message ): void {
		$assignment_id  = match ( $scenario ) {
			'只收檔案的作業帶文字'   => $this->assignment_403_id,
			'只收文字的作業帶附件'   => $this->assignment_400_id,
			'必填文字的作業只帶附件' => $this->assignment_402_id,
			'必填檔案的作業只帶文字' => $this->assignment_401_id,
			'附件數超過max_files'   => $this->assignment_401_id,
		};
		$attachment_ids = match ( $scenario ) {
			'只收檔案的作業帶文字'   => [],
			'只收文字的作業帶附件'   => [ $this->attachment_700_id ],
			'必填文字的作業只帶附件' => [ $this->attachment_700_id ],
			'必填檔案的作業只帶文字' => [],
			'附件數超過max_files'   => [ $this->attachment_700_id, $this->attachment_701_id, $this->attachment_702_id ],
		};

		$response = $this->submit_as( $this->alice_id, $assignment_id, $text_body, $attachment_ids );

		$this->assert_operation_failed_response( $response, $expected_message, 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null, 2: string}>
	 */
	public static function provide_shape_violations(): array {
		return [
			'只收檔案的作業帶文字' => [ '只收檔案的作業帶文字', '我的心得', '此作業不允許文字繳交' ],
			'只收文字的作業帶附件' => [ '只收文字的作業帶附件', 'let x = 1;', '此作業不允許檔案繳交' ],
			'必填文字的作業只帶附件' => [ '必填文字的作業只帶附件', '', '此作業需填寫文字內容' ],
			'必填檔案的作業只帶文字' => [ '必填檔案的作業只帶文字', '如附件', '此作業需上傳檔案' ],
			'附件數超過max_files'   => [ '附件數超過max_files', '如附件', '附件數量超過上限（最多 2 個）' ],
		];
	}

	// ========== 前置（參數）- 文字內容不可超過 50000 字 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- 文字內容不可超過 50000 字
	 * Example: 文字內容超過 50000 字時繳交失敗
	 */
	public function test_文字內容超過50000字時繳交失敗(): void {
		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, \str_repeat( 'a', 50001 ), [] );

		$this->assert_operation_failed_response( $response, '文字內容不可超過 50000 字', 400, 'rest_invalid_param' );
	}

	// ========== 前置（參數）- 每個附件都必須存在且為學員本人上傳 ==========

	/**
	 * @test
	 * @group security
	 * @dataProvider provide_invalid_attachment_ownership
	 *
	 * Rule: 前置（參數）- 每個附件都必須存在且為學員本人上傳
	 * Scenario Outline: 帶入<情境>的附件時繳交失敗
	 *
	 * @param string $scenario 情境名稱（僅供測試名稱可讀性）
	 */
	public function test_附件不存在或非本人上傳時繳交失敗( string $scenario ): void {
		$attachment_ids = ( '不存在' === $scenario ) ? [ 9999 ] : [ $this->attachment_710_id ];

		$response = $this->submit_as( $this->alice_id, $this->assignment_401_id, '如附件', $attachment_ids );

		$this->assert_operation_failed_response( $response, '附件不存在或無權使用', 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_invalid_attachment_ownership(): array {
		return [
			'不存在'   => [ '不存在' ],
			'他人上傳' => [ '他人上傳' ],
		];
	}

	// ========== 後置（狀態）- 首次繳交建立待批改的繳交紀錄 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 首次繳交建立待批改的繳交紀錄
	 * Example: Alice 首次繳交練習二
	 */
	public function test_Alice首次繳交練習二(): void {
		$response = $this->submit_as( $this->alice_id, $this->assignment_401_id, '如附件', [ $this->attachment_700_id, $this->attachment_701_id ] );

		$this->assert_success_response( $response );

		global $wpdb;
		$table          = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;
		$submission_id  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE assignment_id = %d AND user_id = %d", $this->assignment_401_id, $this->alice_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertGreaterThan( 0, $submission_id, '應已建立繳交紀錄' );

		$this->assert_submission_exists(
			$submission_id,
			[
				'text_body'    => '如附件',
				'status'       => 'pending',
				'feedback'     => '',
				'submitted_at' => '2026-09-17 10:00:00',
				'graded_by'    => '',
				'graded_at'    => '',
			]
		);
		$this->assert_submission_attachment_ids( $submission_id, [ $this->attachment_700_id, $this->attachment_701_id ] );
	}

	// ========== 後置（狀態）- 重交覆寫 ==========

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_resubmit_prior_statuses
	 *
	 * Rule: 後置（狀態）- 已有繳交時（不論狀態）覆寫同一筆，狀態重設為待批改並清空評語、批改者與批改時間
	 * Scenario Outline: 前一次狀態為 <前次狀態> 時重交
	 *
	 * @param string $prior_status 前次狀態
	 * @param string $prior_feedback 前次評語
	 */
	public function test_重交時覆寫既有繳交( string $prior_status, string $prior_feedback ): void {
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '舊答案',
				'status'        => $prior_status,
				'feedback'      => $prior_feedback,
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => 1,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);

		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, '新答案', [] );

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$submission_id,
			[
				'text_body'    => '新答案',
				'status'       => 'pending',
				'feedback'     => '',
				'submitted_at' => '2026-09-17 10:00:00',
				'graded_by'    => '',
				'graded_at'    => '',
			]
		);
		$this->assertSame( 1, $this->submission_count( $this->assignment_400_id, $this->alice_id ), '重交後仍應只有 1 筆繳交' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_resubmit_prior_statuses(): array {
		return [
			'fail'    => [ 'fail', '請補充型別說明' ],
			'pass'    => [ 'pass', '寫得很好' ],
			'pending' => [ 'pending', '' ],
		];
	}

	// ========== 後置（狀態）- 超過截止時間仍可繳交 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 超過截止時間仍可繳交
	 * Example: 截止後繳交練習二仍成功
	 */
	public function test_截止後繳交練習二仍成功(): void {
		$this->set_now( '2026-09-20 10:00:00' );

		$response = $this->submit_as( $this->alice_id, $this->assignment_401_id, '補交', [ $this->attachment_700_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'status'       => 'pending',
				'submitted_at' => '2026-09-20 10:00:00',
			]
		);
	}

	// ========== 後置（狀態）- 繳交不改變章節完成狀態 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 繳交不改變章節完成狀態
	 * Example: 繳交後章節仍未完成
	 */
	public function test_繳交後章節仍未完成(): void {
		// 章節 201 無 finished_at（Given 預設狀態）

		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, 'let x = 1;', [] );

		$this->assert_success_response( $response );
		$finished_at = $this->get_chapter_meta( $this->chapter_201_id, $this->alice_id, 'finished_at' );
		$this->assertEmpty( $finished_at, '繳交不應改變章節完成狀態' );
	}

	// ========== 後置（回應）- 回傳繳交紀錄 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳繳交紀錄
	 * Example: 繳交成功後回應繳交資料
	 */
	public function test_繳交成功後回應繳交資料(): void {
		$response = $this->submit_as( $this->alice_id, $this->assignment_400_id, 'let x = 1;', [] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 10:00:00',
			]
		);
	}
}
