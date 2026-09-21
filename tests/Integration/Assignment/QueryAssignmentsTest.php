<?php
/**
 * 查詢章節作業列表 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/assignment/查詢章節作業列表.feature
 * 依據：計劃 §9 P2、§4 DEC-01/13、§4.1、specs/api/api.yml（GET /assignments、GET /assignments/{id}）
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class QueryAssignmentsTest
 * 對應 查詢章節作業列表.feature 全部 Example
 *
 * @group assignment
 * @group issue-131
 */
class QueryAssignmentsTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Alice） */
	private int $alice_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 200（第一章） */
	private int $chapter_200_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，menu_order 10） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，menu_order 0） */
	private int $assignment_401_id;

	/** @var int 作業 402（已刪除練習，trash） */
	private int $assignment_402_id;

	/** @var int 作業 410（第一章總複習，章節 200） */
	private int $assignment_410_id;

	/**
	 * 建立 Background：用戶、課程 100、章節 200/201、作業 400/401/402/410
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_qa_' . \uniqid(),
				'user_email' => 'admin_qa_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_qa_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_qa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_200_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$this->chapter_201_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '1-1 變數',
				'post_parent' => $this->chapter_200_id,
			]
		);

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習一：變數宣告',
				'menu_order' => 10,
			]
		);
		$this->assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => 1789747140,
				'menu_order'    => 0,
			]
		);
		$this->assignment_402_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'       => '已刪除練習',
				'menu_order'  => 5,
				'post_status' => 'trash',
			]
		);
		$this->assignment_410_id = $this->create_assignment(
			$this->chapter_200_id,
			[
				'title'      => '第一章總複習',
				'menu_order' => 0,
			]
		);
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員查詢作業列表時操作失敗
	 */
	public function test_非管理員查詢作業列表時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', '/power-course/assignments' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_not_queryable_assignments
	 *
	 * Rule: 前置（狀態）- 查詢單一作業時作業必須存在，在垃圾桶中的作業視為不存在
	 * Scenario Outline: 查詢<情境>的作業時操作失敗
	 *
	 * @param string $scenario 情境名稱（僅供測試名稱可讀性）
	 */
	public function test_查詢情境的作業時操作失敗( string $scenario ): void {
		\wp_set_current_user( $this->admin_id );

		$assignment_id = ( '不存在' === $scenario ) ? 9999 : $this->assignment_402_id;

		$response = $this->dispatch( 'GET', '/power-course/assignments/' . $assignment_id );

		$this->assert_operation_failed_response( $response, '作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_not_queryable_assignments(): array {
		return [
			'不存在'     => [ '不存在' ],
			'已在垃圾桶' => [ '已在垃圾桶' ],
		];
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 查詢列表時 chapter_id 與 course_id 至少需指定一個
	 * Example: 未指定章節或課程時操作失敗
	 */
	public function test_未指定章節或課程時操作失敗(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/power-course/assignments' );

		$this->assert_operation_failed_response( $response, 'chapter_id 或 course_id 至少需指定一個', 400, 'rest_invalid_param' );
	}

	// ========== 後置（回應）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以 chapter_id 查詢時回傳該章節作業，依 menu_order ASC 排序且不含垃圾桶中的作業
	 * Example: 查詢章節 201 的作業
	 */
	public function test_查詢章節201的作業(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/power-course/assignments', [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_list_ids( $response, [ $this->assignment_401_id, $this->assignment_400_id ] );

		$list = $this->get_response_list( $response );
		$this->assertSame( $this->chapter_201_id, (int) ( $list[0]['chapter_id'] ?? 0 ) );
		$this->assertSame( 0, (int) ( $list[0]['menu_order'] ?? -1 ) );
		$this->assertSame( $this->chapter_201_id, (int) ( $list[1]['chapter_id'] ?? 0 ) );
		$this->assertSame( 10, (int) ( $list[1]['menu_order'] ?? -1 ) );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以 course_id 查詢時回傳整門課程作業，先依章節攤平順序再依 menu_order 排序
	 * Example: 查詢課程 100 的全部作業
	 */
	public function test_查詢課程100的全部作業(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/power-course/assignments', [ 'course_id' => $this->course_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_list_ids(
			$response,
			[ $this->assignment_410_id, $this->assignment_401_id, $this->assignment_400_id ]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以作業 id 查詢時回傳該作業完整設定
	 * Example: 查詢作業 401
	 */
	public function test_查詢作業401(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/power-course/assignments/' . $this->assignment_401_id );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'id'            => $this->assignment_401_id,
				'chapter_id'    => $this->chapter_201_id,
				'course_id'     => $this->course_id,
				'title'         => '練習二：上傳截圖',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_text' => 'no',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => 1789747140,
				'menu_order'    => 0,
			]
		);
	}
}
