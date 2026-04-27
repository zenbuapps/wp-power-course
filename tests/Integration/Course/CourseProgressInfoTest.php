<?php
/**
 * 課程進度資訊 整合測試
 * 測試 Utils\Course::get_course_progress_info() 的 last_chapter_id 守門邏輯
 *
 * Bug：當 last_visit_info.chapter_id 指向 trashed / draft / 已刪除章節時，
 * 若未守門，會導致呼叫者用該 chapter_id 去 get_permalink()，
 * 結果拿到 ugly URL（?post_type=pc_chapter&p=xxx），而非 pretty permalink。
 *
 * 修正：只有章節存在且 post_status === 'publish' 時，才回傳 chapter_id。
 *
 * @group course
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class CourseProgressInfoTest
 * 測試 get_course_progress_info 針對 last_visit_info.chapter_id 的守門行為
 */
class CourseProgressInfoTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試用戶 ID */
	private int $user_id;

	/**
	 * 初始化依賴
	 * CourseUtils 為靜態工具類別，不需要實例化
	 */
	protected function configure_dependencies(): void {
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		$this->user_id = $this->factory()->user->create(
			[
				'user_login' => 'student_' . uniqid(),
				'user_email' => 'student_' . uniqid() . '@test.com',
			]
		);
	}

	/**
	 * 設定 last_visit_info.chapter_id
	 *
	 * @param int $chapter_id 章節 ID
	 */
	private function set_last_visit_chapter( int $chapter_id ): void {
		AVLCourseMeta::update(
			$this->course_id,
			$this->user_id,
			'last_visit_info',
			[
				'chapter_id' => $chapter_id,
				'visited_at' => time(),
			]
		);
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 基本冒煙測試：確認 get_course_progress_info 方法可被呼叫且回傳預期結構
	 */
	public function test_get_course_progress_info_方法可被呼叫(): void {
		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'progress', $result );
		$this->assertArrayHasKey( 'last_chapter_id', $result );
		$this->assertArrayHasKey( 'last_position_seconds', $result );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 當 last_visit_info.chapter_id 指向 publish 章節時，應正常回傳 chapter_id
	 */
	public function test_當章節為_publish_時應回傳_last_chapter_id(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_status' => 'publish' ]
		);
		$this->set_last_visit_chapter( $chapter_id );

		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertSame( $chapter_id, $result['last_chapter_id'] );
	}

	// ========== 邊界測試（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 當 last_visit_info.chapter_id 指向已 trashed 的章節時，應回傳 null
	 * 避免呼叫者用 trashed chapter_id 取得 ugly URL
	 */
	public function test_當章節為_trash_時_last_chapter_id_應為_null(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_status' => 'publish' ]
		);
		$this->set_last_visit_chapter( $chapter_id );

		\wp_trash_post( $chapter_id );

		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertNull(
			$result['last_chapter_id'],
			'trashed 章節不應作為 last_chapter_id 回傳，否則會導致 ugly URL'
		);
	}

	/**
	 * @test
	 * @group edge
	 * 當 last_visit_info.chapter_id 指向 draft 章節時，應回傳 null
	 */
	public function test_當章節為_draft_時_last_chapter_id_應為_null(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_status' => 'draft' ]
		);
		$this->set_last_visit_chapter( $chapter_id );

		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertNull( $result['last_chapter_id'] );
	}

	/**
	 * @test
	 * @group edge
	 * 當 last_visit_info.chapter_id 指向已完全刪除（不存在）的章節時，應回傳 null
	 */
	public function test_當章節已被刪除時_last_chapter_id_應為_null(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_status' => 'publish' ]
		);
		$this->set_last_visit_chapter( $chapter_id );

		\wp_delete_post( $chapter_id, true );

		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertNull( $result['last_chapter_id'] );
	}

	/**
	 * @test
	 * @group edge
	 * 當 last_visit_info 為空（用戶從未訪問過任何章節）時，last_chapter_id 應為 null
	 */
	public function test_當沒有_last_visit_info_時_last_chapter_id_應為_null(): void {
		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertNull( $result['last_chapter_id'] );
	}

	/**
	 * @test
	 * @group edge
	 * 當 trashed 章節導致 last_chapter_id 為 null 時，last_position_seconds 應為 0
	 * 驗證兩個欄位的聯動：last_chapter_id 為 null → 不查 ChapterProgress → 秒數為 0
	 */
	public function test_當章節為_trash_時_last_position_seconds_應為_0(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[ 'post_status' => 'publish' ]
		);
		$this->set_last_visit_chapter( $chapter_id );

		\wp_trash_post( $chapter_id );

		$result = CourseUtils::get_course_progress_info( $this->course_id, $this->user_id );

		$this->assertSame( 0, $result['last_position_seconds'] );
	}
}
