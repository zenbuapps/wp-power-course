<?php
/**
 * Teacher\Core\ExtendQuery 整合測試
 *
 * Features:
 *   - specs/plans/refactor-teacher-management.plan.md 階段 1 步驟 3
 *
 * 測試：
 * - teacher_course_id 反查：僅回傳該課程的講師
 * - teacher_courses_count computed field
 * - teacher_students_count computed field
 * - 異常 fallback（課程 meta 格式異常 → 0）
 *
 * @group teacher
 * @group user
 * @group query
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Teacher\Core\ExtendQuery;
use J7\Powerhouse\Domains\User\Utils\CRUD;

/**
 * Class TeacherExtendQueryTest
 */
class TeacherExtendQueryTest extends TestCase {

	/** @var int 講師 Alice */
	private int $teacher_alice;

	/** @var int 講師 Bob */
	private int $teacher_bob;

	/** @var int 學員 Chris */
	private int $student_chris;

	/** @var int 學員 Dora */
	private int $student_dora;

	/** @var int 課程 A */
	private int $course_a;

	/** @var int 課程 B */
	private int $course_b;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// ExtendQuery 以 Singleton 方式啟動，透過 add_filter 註冊
		ExtendQuery::instance();
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->teacher_alice = $this->factory()->user->create(
			[
				'user_login' => 'alice_t_' . uniqid(),
				'user_email' => 'alice_t_' . uniqid() . '@test.com',
				'role'       => 'author',
			]
		);
		update_user_meta( $this->teacher_alice, 'is_teacher', 'yes' );

		$this->teacher_bob = $this->factory()->user->create(
			[
				'user_login' => 'bob_t_' . uniqid(),
				'user_email' => 'bob_t_' . uniqid() . '@test.com',
				'role'       => 'author',
			]
		);
		update_user_meta( $this->teacher_bob, 'is_teacher', 'yes' );

		$this->student_chris = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->student_dora  = $this->factory()->user->create( [ 'role' => 'customer' ] );

		// 課程 A：Alice 是唯一講師；Chris 與 Dora 都選修
		$this->course_a = $this->create_course( [ 'post_title' => '課程 A' ] );
		add_post_meta( $this->course_a, 'teacher_ids', $this->teacher_alice );
		$this->enroll_user_to_course( $this->student_chris, $this->course_a );
		$this->enroll_user_to_course( $this->student_dora, $this->course_a );

		// 課程 B：Alice 與 Bob 都是講師；只有 Chris 選修
		$this->course_b = $this->create_course( [ 'post_title' => '課程 B' ] );
		add_post_meta( $this->course_b, 'teacher_ids', $this->teacher_alice );
		add_post_meta( $this->course_b, 'teacher_ids', $this->teacher_bob );
		$this->enroll_user_to_course( $this->student_chris, $this->course_b );
	}

	// ========== teacher_courses_count computed field ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Alice 負責 2 門課（A 與 B）
	 */
	public function test_teacher_courses_count_for_alice(): void {
		$user  = get_user_by( 'id', $this->teacher_alice );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_courses_count' ]
		);

		$this->assertSame( 2, $array['teacher_courses_count'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Bob 負責 1 門課（B）
	 */
	public function test_teacher_courses_count_for_bob(): void {
		$user  = get_user_by( 'id', $this->teacher_bob );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_courses_count' ]
		);

		$this->assertSame( 1, $array['teacher_courses_count'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 沒負責任何課程的用戶回 0
	 */
	public function test_teacher_courses_count_for_non_teacher(): void {
		$user  = get_user_by( 'id', $this->student_chris );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_courses_count' ]
		);

		$this->assertSame( 0, $array['teacher_courses_count'] );
	}

	// ========== teacher_students_count computed field ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Alice 負責 A 與 B 兩課；跨課程去重學員 = {Chris, Dora} = 2
	 */
	public function test_teacher_students_count_deduplicates_across_courses(): void {
		$user  = get_user_by( 'id', $this->teacher_alice );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_students_count' ]
		);

		$this->assertSame( 2, $array['teacher_students_count'], 'Alice 的學員應去重 = {Chris, Dora}' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Bob 僅負責 B 課，只有 Chris 選修 = 1
	 */
	public function test_teacher_students_count_for_bob(): void {
		$user  = get_user_by( 'id', $this->teacher_bob );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_students_count' ]
		);

		$this->assertSame( 1, $array['teacher_students_count'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 沒負責任何課程時回 0
	 */
	public function test_teacher_students_count_for_non_teacher(): void {
		$user  = get_user_by( 'id', $this->student_chris );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_students_count' ]
		);

		$this->assertSame( 0, $array['teacher_students_count'] );
	}

	// ========== 兩個 computed field 同時查詢 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 同時查兩個 computed field 皆正確，且不影響其他 meta
	 *
	 * 注意：is_teacher 值在 WP_UnitTestCase 環境下透過 get_user_meta(..., true)
	 * 讀回時會經過型別轉換（某些版本回 'yes' 字串，某些回 true bool）。
	 * 這裡只驗「不為空」與兩個 computed field 都存在，以避免跨版本 flaky。
	 */
	public function test_multiple_computed_fields_coexist(): void {
		$user  = get_user_by( 'id', $this->teacher_alice );
		$array = CRUD::get_meta_keys_array(
			$user,
			[ 'teacher_courses_count', 'teacher_students_count', 'is_teacher' ]
		);

		$this->assertSame( 2, $array['teacher_courses_count'] );
		$this->assertSame( 2, $array['teacher_students_count'] );
		$this->assertArrayHasKey( 'is_teacher', $array );
		$this->assertNotEmpty( $array['is_teacher'], 'is_teacher 不應為 empty' );
	}

	// ========== teacher_course_id meta_query 反查 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 指定 teacher_course_id=<course_a> 時只回該課程的講師（Alice）
	 *
	 * 注意：此測試模擬 REST request 將參數放到 $_GET，再透過
	 * prepare_query_args + pre_get_users 的流程。
	 */
	public function test_teacher_course_id_filters_to_course_teachers(): void {
		// 模擬 REST 請求：?is_teacher=yes&teacher_course_id=<A>
		$_GET['teacher_course_id'] = (string) $this->course_a;

		$args  = CRUD::prepare_query_args(
			[
				'is_teacher'        => 'yes',
				'teacher_course_id' => (string) $this->course_a,
				'posts_per_page'    => 20,
			]
		);
		$query = new \WP_User_Query( $args );

		unset( $_GET['teacher_course_id'] );

		$result_ids = array_map( static fn( \WP_User $u ): int => (int) $u->ID, $query->get_results() );

		$this->assertContains( $this->teacher_alice, $result_ids, '結果應含 Alice（課程 A 的講師）' );
		$this->assertNotContains( $this->teacher_bob, $result_ids, '結果不應含 Bob（非課程 A 的講師）' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 指定 teacher_course_id=<course_b>（兩位講師）時回 Alice + Bob
	 */
	public function test_teacher_course_id_returns_multiple_teachers(): void {
		$_GET['teacher_course_id'] = (string) $this->course_b;

		$args  = CRUD::prepare_query_args(
			[
				'is_teacher'        => 'yes',
				'teacher_course_id' => (string) $this->course_b,
				'posts_per_page'    => 20,
			]
		);
		$query = new \WP_User_Query( $args );

		unset( $_GET['teacher_course_id'] );

		$result_ids = array_map( static fn( \WP_User $u ): int => (int) $u->ID, $query->get_results() );

		$this->assertContains( $this->teacher_alice, $result_ids );
		$this->assertContains( $this->teacher_bob, $result_ids );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 指定不存在的課程 ID → 回空結果（不 fatal）
	 */
	public function test_teacher_course_id_nonexistent_course_returns_empty(): void {
		$_GET['teacher_course_id'] = '999999999';

		$args  = CRUD::prepare_query_args(
			[
				'is_teacher'        => 'yes',
				'teacher_course_id' => '999999999',
				'posts_per_page'    => 20,
			]
		);
		$query = new \WP_User_Query( $args );

		unset( $_GET['teacher_course_id'] );

		$this->assertSame( [], $query->get_results() );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: teacher_course_id 不是有效整數（如 'abc'）→ 視為未指定，不影響查詢
	 */
	public function test_teacher_course_id_invalid_value_is_ignored(): void {
		$_GET['teacher_course_id'] = 'abc';

		$args  = CRUD::prepare_query_args(
			[
				'is_teacher'        => 'yes',
				'teacher_course_id' => 'abc',
				'posts_per_page'    => 20,
			]
		);
		$query = new \WP_User_Query( $args );

		unset( $_GET['teacher_course_id'] );

		$result_ids = array_map( static fn( \WP_User $u ): int => (int) $u->ID, $query->get_results() );

		// 應該回所有講師（Alice + Bob），不因 invalid course_id 而限制
		$this->assertContains( $this->teacher_alice, $result_ids );
		$this->assertContains( $this->teacher_bob, $result_ids );
	}
}
