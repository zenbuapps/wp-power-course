<?php
/**
 * 批改通過觸發課程完成時，必須以「學員」的進度判斷（Issue #131 第二波 bug fix）
 *
 * ─── 背景 ──────────────────────────────────────────────────────
 * Chapter\Core\LifeCycle::add_chapter_finish_log( $chapter_id, $course_id, $user_id ) 收到的是學員 ID，
 * 但判斷課程是否完成時呼叫 Utils\Course::get_course_progress( $course_id ) 沒傳 $user_id，
 * 於是退回 get_current_user_id()。
 *
 * 作業功能之前，章節完成一律由學員自己在教室觸發（目前登入者 = 學員），這個缺陷不會浮現；
 * 作業批改通過時，Service\Grading::maybe_finish_chapter() 由「管理員」替學員完成章節，
 * 課程完成判斷就變成拿管理員的進度：
 *   a. 學員通過最後一份作業（學員進度 100）→ 看的是管理員進度 → 課程完成不觸發
 *   b. 管理員自己進度剛好 100 → 學員還沒完成卻誤觸發
 *
 * ─── 防說謊 ────────────────────────────────────────────────────
 * 觸發與否的斷言之前，先斷言前置狀態（批改成功、學員章節確實被標記完成、管理員 / 學員的實際進度），
 * 讓紅燈只可能落在「觸發 / 沒觸發」本身，而不是 fixture 沒建好。
 * 管理員自己的章節完成用 set_chapter_finished() 直寫 chaptermeta，不經 Progress::mark_finished()，
 * 避免在 fixture 階段就替管理員觸發一次課程完成、污染捕捉紀錄。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Course\LifeCycle as CourseLifeCycle;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class GradingCompletesCourseForStudentTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class GradingCompletesCourseForStudentTest extends AssignmentTestCase {

	/** @var int 管理員（批改者） */
	private int $admin_id;

	/** @var int 學員 */
	private int $student_id;

	/** @var array<int, array{0:int,1:int}> 掛在 COURSE_FINISHED_ACTION 的捕捉紀錄（course_id, user_id） */
	private array $captured_course_finished = [];

	/**
	 * 建立管理員與學員，掛上課程完成 action 的捕捉 listener
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id   = $this->factory()->user->create(
			[
				'user_login' => 'admin_course_finish_' . \uniqid(),
				'user_email' => 'admin_course_finish_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->student_id = $this->factory()->user->create(
			[
				'user_login' => 'student_course_finish_' . \uniqid(),
				'user_email' => 'student_course_finish_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->captured_course_finished = [];
		\add_action( CourseLifeCycle::COURSE_FINISHED_ACTION, [ $this, 'capture_course_finished' ], 10, 2 );
	}

	/**
	 * 移除捕捉 listener
	 */
	public function tear_down(): void {
		\remove_action( CourseLifeCycle::COURSE_FINISHED_ACTION, [ $this, 'capture_course_finished' ], 10 );

		parent::tear_down();
	}

	/**
	 * 捕捉 COURSE_FINISHED_ACTION 觸發時的參數
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   用戶 ID
	 */
	public function capture_course_finished( int $course_id, int $user_id ): void {
		$this->captured_course_finished[] = [ $course_id, $user_id ];
	}

	/**
	 * 以管理員身分經 REST 批改
	 *
	 * @param int    $submission_id 繳交 ID
	 * @param string $status        批改狀態
	 * @return \WP_REST_Response
	 */
	private function grade_as_admin( int $submission_id, string $status ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch(
			'PATCH',
			'/power-course/assignment-submissions/' . $submission_id . '/grade',
			[ 'status' => $status ]
		);
	}

	/**
	 * @test
	 *
	 * 學員通過最後一份作業 → 課程完成觸發給學員
	 * 課程只有 1 個章節、1 份作業；管理員自己沒有任何進度（0）
	 */
	public function test_學員通過最後一份作業時課程完成觸發給學員(): void {
		$course_id     = $this->create_course( [ 'post_title' => '單章節課程' ] );
		$chapter_id    = $this->create_chapter( $course_id, [ 'post_title' => '唯一章節' ] );
		$assignment_id = $this->create_assignment( $chapter_id, [ 'title' => '唯一作業' ] );

		$this->enroll_user_to_course( $this->student_id, $course_id, 0 );

		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_id,
				'user_id'       => $this->student_id,
				'text_body'     => '我的作答',
				'status'        => 'pending',
			]
		);

		$response = $this->grade_as_admin( $submission_id, 'pass' );

		// 前置：批改成功、學員章節確實被標記完成、學員進度 100
		$this->assert_success_response( $response );
		$this->assertNotEmpty(
			$this->get_chapter_meta( $chapter_id, $this->student_id, 'finished_at' ),
			'批改通過後學員的章節應被標記完成'
		);
		$this->assertSame(
			100.0,
			CourseUtils::get_course_progress( $course_id, $this->student_id ),
			'學員通過唯一一份作業後課程進度應為 100'
		);

		// 主斷言：課程完成觸發一次，且對象是學員
		$this->assertCount(
			1,
			$this->captured_course_finished,
			\sprintf(
				'學員進度已達 100，課程完成 action 應觸發一次，實際觸發 %d 次（%s）',
				\count( $this->captured_course_finished ),
				(string) \wp_json_encode( $this->captured_course_finished )
			)
		);
		$this->assertSame(
			[ $course_id, $this->student_id ],
			$this->captured_course_finished[0],
			'課程完成 action 的參數應為（課程 ID, 學員 ID）'
		);
	}

	/**
	 * @test
	 *
	 * 管理員自己進度 100 不得替未完成的學員誤觸發
	 * 課程 2 個章節、各 1 份作業；管理員 2 章皆完成（100）；學員只通過第 1 章的作業（50）
	 */
	public function test_管理員自己進度100時不得替未完成的學員觸發課程完成(): void {
		$course_id       = $this->create_course( [ 'post_title' => '雙章節課程' ] );
		$chapter_1_id    = $this->create_chapter(
			$course_id,
			[
				'post_title' => '第 1 章',
				'menu_order' => 0,
			]
		);
		$chapter_2_id    = $this->create_chapter(
			$course_id,
			[
				'post_title' => '第 2 章',
				'menu_order' => 10,
			]
		);
		$assignment_1_id = $this->create_assignment( $chapter_1_id, [ 'title' => '第 1 章作業' ] );
		$this->create_assignment( $chapter_2_id, [ 'title' => '第 2 章作業' ] );

		$this->enroll_user_to_course( $this->student_id, $course_id, 0 );

		// 管理員自己 2 章都完成（直寫 chaptermeta，不觸發任何 action）
		$this->set_chapter_finished( $chapter_1_id, $this->admin_id, '2026-09-01 10:00:00' );
		$this->set_chapter_finished( $chapter_2_id, $this->admin_id, '2026-09-01 10:00:00' );

		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_1_id,
				'user_id'       => $this->student_id,
				'text_body'     => '第 1 章作答',
				'status'        => 'pending',
			]
		);

		// 前置：管理員自己的課程進度確實是 100
		$this->assertSame(
			100.0,
			CourseUtils::get_course_progress( $course_id, $this->admin_id ),
			'前置條件：管理員自己 2 章皆完成，課程進度應為 100'
		);

		$response = $this->grade_as_admin( $submission_id, 'pass' );

		// 前置：批改成功、學員第 1 章被標記完成、第 2 章未完成、學員進度 50
		$this->assert_success_response( $response );
		$this->assertNotEmpty(
			$this->get_chapter_meta( $chapter_1_id, $this->student_id, 'finished_at' ),
			'批改通過後學員的第 1 章應被標記完成'
		);
		$this->assertEmpty(
			$this->get_chapter_meta( $chapter_2_id, $this->student_id, 'finished_at' ),
			'學員的第 2 章不應被標記完成'
		);
		$this->assertSame(
			50.0,
			CourseUtils::get_course_progress( $course_id, $this->student_id ),
			'學員只完成 1 / 2 章，課程進度應為 50'
		);

		// 主斷言：學員未完成課程，課程完成 action 不得觸發
		$this->assertSame(
			[],
			$this->captured_course_finished,
			'學員進度只有 50，課程完成 action 不應觸發（不得拿管理員的進度判斷），實際觸發：'
				. (string) \wp_json_encode( $this->captured_course_finished )
		);
	}
}
