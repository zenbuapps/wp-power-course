<?php
/**
 * 建立作業 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/assignment/建立作業.feature
 * 依據：計劃 §9 P2、§4 DEC-01/05/18、§4.1、specs/api/api.yml（POST /assignments）
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class CreateAssignmentTest
 * 對應 建立作業.feature 全部 Example
 *
 * @group assignment
 * @group issue-131
 */
class CreateAssignmentTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Alice） */
	private int $alice_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數，父章節為 200） */
	private int $chapter_201_id;

	/**
	 * 建立 Background：用戶、課程 100、章節 200／201
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_ca_' . \uniqid(),
				'user_email' => 'admin_ca_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_ca_' . \uniqid(),
				'user_email' => 'alice_ca_' . \uniqid() . '@test.com',
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
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員建立作業時操作失敗
	 */
	public function test_非管理員建立作業時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'POST', '/power-course/assignments' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 章節必須存在
	 * Example: 章節不存在時建立失敗
	 */
	public function test_章節不存在時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => 9999,
				'title'      => '練習一：變數宣告',
			]
		);

		$this->assert_operation_failed_response( $response, '章節不存在', 404, 'rest_not_found' );
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- chapter_id 為必填
	 * Example: 未提供 chapter_id 時建立失敗
	 */
	public function test_未提供chapter_id時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'title' => '練習一：變數宣告',
			]
		);

		$this->assert_operation_failed_response( $response, 'chapter_id 為必填', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_invalid_title
	 *
	 * Rule: 前置（參數）- title 長度必須為 1 至 200 字
	 * Scenario Outline: title 為 <情境> 時建立失敗
	 *
	 * @param string $title 不合法的 title
	 */
	public function test_title為情境時建立失敗( string $title ): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => $title,
			]
		);

		$this->assert_operation_failed_response( $response, 'title 長度必須為 1 至 200 字', 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_invalid_title(): array {
		return [
			'空字串'      => [ '' ],
			'超過 200 字' => [ \str_repeat( '字', 201 ) ],
		];
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- instructions 不可超過 20000 字
	 * Example: 作業說明超過 20000 字時建立失敗
	 */
	public function test_instructions超過20000字時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id'   => $this->chapter_201_id,
				'title'        => '練習一：變數宣告',
				'instructions' => \str_repeat( '字', 20001 ),
			]
		);

		$this->assert_operation_failed_response( $response, 'instructions 不可超過 20000 字', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- allow_text 與 allow_file 至少一個為 yes
	 * Example: 文字與檔案繳交皆關閉時建立失敗
	 */
	public function test_文字與檔案繳交皆關閉時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習一：變數宣告',
				'allow_text' => 'no',
				'allow_file' => 'no',
			]
		);

		$this->assert_operation_failed_response( $response, '至少需允許文字或檔案其中一種繳交方式', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_invalid_max_files
	 *
	 * Rule: 前置（參數）- max_files 必須為 1 至 20 的整數
	 * Scenario Outline: max_files 為 <max_files> 時建立失敗
	 *
	 * @param int $max_files 不合法的 max_files
	 */
	public function test_max_files為值時建立失敗( int $max_files ): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習二：上傳截圖',
				'allow_file' => 'yes',
				'max_files'  => $max_files,
			]
		);

		$this->assert_operation_failed_response( $response, 'max_files 必須為 1 至 20 的整數', 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function provide_invalid_max_files(): array {
		return [
			'0'  => [ 0 ],
			'21' => [ 21 ],
		];
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- due_at 必須為 0 或 10 位 Unix timestamp
	 * Example: due_at 不是 10 位 timestamp 時建立失敗
	 */
	public function test_due_at不是10位timestamp時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習一：變數宣告',
				'due_at'     => 12345,
			]
		);

		$this->assert_operation_failed_response( $response, 'due_at 必須為 0 或 10 位 Unix timestamp', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- menu_order 必須為大於等於 0 的整數
	 * Example: menu_order 為負數時建立失敗
	 */
	public function test_menu_order為負數時建立失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習一：變數宣告',
				'menu_order' => -1,
			]
		);

		$this->assert_operation_failed_response( $response, 'menu_order 必須為大於等於 0 的整數', 400, 'rest_invalid_param' );
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 只提供必填欄位時建立 publish 作業並套用預設值
	 * Example: 只給章節與標題時套用預設題型設定
	 */
	public function test_只給章節與標題時套用預設題型設定(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習一：變數宣告',
			]
		);

		$this->assert_success_response( $response );

		$data          = $response->get_data();
		$assignment_id = (int) ( \is_array( $data ) ? ( $data['id'] ?? 0 ) : 0 );
		$this->assertGreaterThan( 0, $assignment_id, '回應應含新建作業 id' );

		$this->assert_assignment_fields(
			$assignment_id,
			[
				'post_parent'      => $this->chapter_201_id,
				'parent_course_id' => $this->course_id,
				'title'            => '練習一：變數宣告',
				'allow_text'       => 'yes',
				'allow_file'       => 'no',
				'required_text'    => 'no',
				'required_file'    => 'no',
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
	 * Rule: 後置（狀態）- 提供的說明、題型設定、截止時間與排序照實寫入
	 * Example: 建立需上傳檔案且有截止時間的作業
	 */
	public function test_建立需上傳檔案且有截止時間的作業(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id'    => $this->chapter_201_id,
				'title'         => '練習二：上傳截圖',
				'instructions'  => '<p>請上傳執行結果</p>',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_text' => 'no',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => 1789747140,
				'menu_order'    => 10,
			]
		);

		$this->assert_success_response( $response );

		$data          = $response->get_data();
		$assignment_id = (int) ( \is_array( $data ) ? ( $data['id'] ?? 0 ) : 0 );

		$this->assert_assignment_fields(
			$assignment_id,
			[
				'post_parent'      => $this->chapter_201_id,
				'parent_course_id' => $this->course_id,
				'title'            => '練習二：上傳截圖',
				'instructions'     => '<p>請上傳執行結果</p>',
				'allow_text'       => 'yes',
				'allow_file'       => 'yes',
				'required_text'    => 'no',
				'required_file'    => 'yes',
				'max_files'        => 2,
				'due_at'           => 1789747140,
				'menu_order'       => 10,
				'post_status'      => 'publish',
			]
		);
	}

	// ========== 後置（回應）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳新建作業的完整設定
	 * Example: 建立成功後回應含作業 id 與所屬課程
	 */
	public function test_建立成功後回應含作業id與所屬課程(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments',
			[
				'chapter_id' => $this->chapter_201_id,
				'title'      => '練習一：變數宣告',
			]
		);

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'chapter_id' => $this->chapter_201_id,
				'course_id'  => $this->course_id,
				'title'      => '練習一：變數宣告',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'due_at'     => 0,
				'menu_order' => 0,
			]
		);
	}
}
