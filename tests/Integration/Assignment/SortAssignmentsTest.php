<?php
/**
 * 排序作業 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/assignment/排序作業.feature
 * 依據：計劃 §9 P2、§4 DEC-01/13、§4.1、specs/api/api.yml（POST /assignments/sort）
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class SortAssignmentsTest
 * 對應 排序作業.feature 全部 Example
 *
 * @group assignment
 * @group issue-131
 */
class SortAssignmentsTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Alice） */
	private int $alice_id;

	/** @var int 章節 200（第一章） */
	private int $chapter_200_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，章節 201） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，章節 201） */
	private int $assignment_401_id;

	/** @var int 作業 410（第一章總複習，章節 200） */
	private int $assignment_410_id;

	/**
	 * 建立 Background：用戶、課程 100、章節 200/201、作業 400/401/410
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_sa_' . \uniqid(),
				'user_email' => 'admin_sa_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_sa_' . \uniqid(),
				'user_email' => 'alice_sa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$course_id            = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_200_id = $this->create_chapter( $course_id, [ 'post_title' => '第一章' ] );
		$this->chapter_201_id = $this->create_chapter(
			$course_id,
			[
				'post_title'  => '1-1 變數',
				'post_parent' => $this->chapter_200_id,
			]
		);

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[ 'title' => '練習一：變數宣告' ]
		);
		$this->assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
			]
		);
		$this->assignment_410_id = $this->create_assignment(
			$this->chapter_200_id,
			[ 'title' => '第一章總複習' ]
		);
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員排序作業時操作失敗
	 */
	public function test_非管理員排序作業時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'POST', '/power-course/assignments/sort' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_invalid_ids_cases
	 *
	 * Rule: 前置（狀態）- ids 中每一個 id 都必須對應到存在的作業且不可重複
	 * Scenario Outline: ids 為 <ids> 時操作失敗
	 *
	 * @param string $case 情境代號：not_exist（[400, 9999]）或 duplicate（[400, 400]）
	 */
	public function test_ids為值時操作失敗( string $case ): void {
		\wp_set_current_user( $this->admin_id );

		$ids = match ( $case ) {
			'not_exist' => [ $this->assignment_400_id, 9999 ],
			'duplicate' => [ $this->assignment_400_id, $this->assignment_400_id ],
		};

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => $ids,
			]
		);

		$this->assert_operation_failed_response( $response, '部分作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_invalid_ids_cases(): array {
		return [
			'[400, 9999]' => [ 'not_exist' ],
			'[400, 400]'  => [ 'duplicate' ],
		];
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- ids 中每一份作業都必須屬於指定的章節
	 * Example: ids 混入其他章節的作業時操作失敗
	 */
	public function test_ids混入其他章節的作業時操作失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => [ $this->assignment_400_id, $this->assignment_410_id ],
			]
		);

		$this->assert_operation_failed_response( $response, '作業不屬於指定的章節', 400, 'rest_invalid_param' );
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- chapter_id 為必填
	 * Example: 未指定章節時操作失敗
	 */
	public function test_未指定章節時操作失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/power-course/assignments/sort' );

		$this->assert_operation_failed_response( $response, 'chapter_id 為必填', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- ids 最多 200 筆
	 * Example: ids 超過 200 筆時操作失敗
	 */
	public function test_ids超過200筆時操作失敗(): void {
		\wp_set_current_user( $this->admin_id );

		// 201 個假 id（不必真的存在，數量驗證應早於存在性驗證）
		$ids = \range( 1, 201 );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => $ids,
			]
		);

		$this->assert_operation_failed_response( $response, 'ids 最多 200 筆', 400, 'rest_invalid_param' );
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 依 ids 先後順序以 10 為間隔寫入 menu_order
	 * Example: 將練習二排到練習一之前
	 */
	public function test_將練習二排到練習一之前(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => [ $this->assignment_401_id, $this->assignment_400_id ],
			]
		);

		$this->assert_success_response( $response );
		$this->assert_assignment_fields( $this->assignment_401_id, [ 'menu_order' => 10 ] );
		$this->assert_assignment_fields( $this->assignment_400_id, [ 'menu_order' => 20 ] );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- ids 為空陣列時不變更任何排序
	 * Example: 空陣列不改變排序
	 */
	public function test_空陣列不改變排序(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => [],
			]
		);

		$this->assert_success_response( $response );
		$this->assert_assignment_fields( $this->assignment_400_id, [ 'menu_order' => 0 ] );
		$this->assert_assignment_fields( $this->assignment_401_id, [ 'menu_order' => 0 ] );
	}

	// ========== 後置（回應）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳該章節排序後的作業列表
	 * Example: 排序後回傳新的順序
	 */
	public function test_排序後回傳新的順序(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch(
			'POST',
			'/power-course/assignments/sort',
			[
				'chapter_id' => $this->chapter_201_id,
				'ids'        => [ $this->assignment_401_id, $this->assignment_400_id ],
			]
		);

		$this->assert_success_response( $response );
		$this->assert_response_list_ids( $response, [ $this->assignment_401_id, $this->assignment_400_id ] );

		$list = $this->get_response_list( $response );
		$this->assertSame( '練習二：上傳截圖', $list[0]['title'] ?? null );
		$this->assertSame( 10, (int) ( $list[0]['menu_order'] ?? -1 ) );
		$this->assertSame( '練習一：變數宣告', $list[1]['title'] ?? null );
		$this->assertSame( 20, (int) ( $list[1]['menu_order'] ?? -1 ) );
	}
}
