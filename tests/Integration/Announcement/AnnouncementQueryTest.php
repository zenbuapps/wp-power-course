<?php
/**
 * Announcement Query 測試
 *
 * Feature: specs/features/announcement/查詢公告列表.feature
 *
 * @group announcement
 * @group query
 */

declare( strict_types=1 );

namespace Tests\Integration\Announcement;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Announcement\Core\CPT;
use J7\PowerCourse\Resources\Announcement\Service\Crud;
use J7\PowerCourse\Resources\Announcement\Service\Query;
use J7\PowerCourse\Resources\Announcement\Utils\Utils;

/**
 * Class AnnouncementQueryTest
 */
class AnnouncementQueryTest extends TestCase {

	private int $course_id;
	private int $enrolled_user_id;
	private int $guest_user_id;

	protected function configure_dependencies(): void {
		// no-op
	}

	public function set_up(): void {
		parent::set_up();
		$this->course_id        = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->enrolled_user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->guest_user_id    = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->enroll_user_to_course( $this->enrolled_user_id, $this->course_id );

		wp_set_current_user(
			$this->factory()->user->create( [ 'role' => 'administrator' ] )
		);
	}

	/**
	 * 建立公告 helper（直接走 wp_insert_post 以精確控制各欄位）
	 *
	 * @param array<string, mixed> $args 參數
	 * @return int
	 */
	private function insert_announcement( array $args ): int {
		$defaults = [
			'post_type'   => CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_parent' => $this->course_id,
			'post_title'  => 'Test',
		];
		$args     = array_merge( $defaults, $args );
		$meta     = $args['meta_input'] ?? [];
		unset( $args['meta_input'] );

		$id = $this->factory()->post->create( $args );
		update_post_meta( $id, 'parent_course_id', (int) $args['post_parent'] );
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	/**
	 * @test
	 * @group happy
	 * 後台預設不含 trash
	 */
	public function test_後台列表預設不含trash(): void {
		$pub = $this->insert_announcement( [ 'post_title' => '公告 A' ] );
		$tr  = $this->insert_announcement(
			[
				'post_title'  => '已刪除公告',
				'post_status' => 'publish',
			]
		);
		wp_trash_post( $tr );

		$list = Query::list( [ 'parent_course_id' => $this->course_id ] );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		$this->assertContains( $pub, $ids );
		$this->assertNotContains( $tr, $ids );
	}

	/**
	 * @test
	 * @group happy
	 * 後台可指定包含 trash
	 */
	public function test_後台列表可指定含trash(): void {
		$pub = $this->insert_announcement( [ 'post_title' => '公告 A' ] );
		$tr  = $this->insert_announcement( [ 'post_title' => '已刪除公告' ] );
		wp_trash_post( $tr );

		$list = Query::list(
			[
				'parent_course_id' => $this->course_id,
				'post_status'      => 'publish,future,trash',
			]
		);
		$ids = array_map( fn( $a ) => (int) $a['id'], $list );
		$this->assertContains( $pub, $ids );
		$this->assertContains( $tr, $ids );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_後台列表依post_date_DESC排序(): void {
		$old   = $this->insert_announcement(
			[
				'post_title' => '舊',
				'post_date'  => '2026-01-01 10:00:00',
			]
		);
		$mid   = $this->insert_announcement(
			[
				'post_title' => '中',
				'post_date'  => '2026-02-01 10:00:00',
			]
		);
		$newer = $this->insert_announcement(
			[
				'post_title' => '新',
				'post_date'  => '2026-03-01 10:00:00',
			]
		);

		$list = Query::list( [ 'parent_course_id' => $this->course_id ] );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		$this->assertSame( [ $newer, $mid, $old ], $ids );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_status_label_active(): void {
		$id = $this->insert_announcement(
			[
				'post_title' => '生效公告',
				'meta_input' => [
					'visibility' => 'public',
				],
			]
		);
		$got = Query::get( $id );
		$this->assertSame( Utils::STATUS_LABEL_ACTIVE, $got['status_label'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_status_label_scheduled(): void {
		$id = $this->insert_announcement(
			[
				'post_title'  => '排程公告',
				'post_status' => 'future',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$got = Query::get( $id );
		$this->assertSame( Utils::STATUS_LABEL_SCHEDULED, $got['status_label'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_status_label_expired(): void {
		$id = $this->insert_announcement(
			[
				'post_title' => '過期公告',
				'meta_input' => [
					'end_at' => 1700000000, // 2023-11-14
				],
			]
		);
		$got = Query::get( $id );
		$this->assertSame( Utils::STATUS_LABEL_EXPIRED, $got['status_label'] );
	}

	// ========== 公開列表 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 訪客（未登入或未購）只看 visibility=public 且生效中的公告
	 */
	public function test_公開列表_訪客只看public且生效公告(): void {
		$pub_active = $this->insert_announcement(
			[
				'post_title' => '永久公開',
				'meta_input' => [ 'visibility' => 'public' ],
			]
		);
		$pub_expired = $this->insert_announcement(
			[
				'post_title' => '已過期',
				'meta_input' => [
					'visibility' => 'public',
					'end_at'     => 1700000000,
				],
			]
		);
		$enrolled_only = $this->insert_announcement(
			[
				'post_title' => '僅學員',
				'meta_input' => [ 'visibility' => 'enrolled' ],
			]
		);
		$scheduled = $this->insert_announcement(
			[
				'post_title'  => '排程中',
				'post_status' => 'future',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$list = Query::list_public( $this->course_id, $this->guest_user_id );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		$this->assertContains( $pub_active, $ids );
		$this->assertNotContains( $pub_expired, $ids );
		$this->assertNotContains( $enrolled_only, $ids );
		$this->assertNotContains( $scheduled, $ids );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_公開列表_已購學員可看到enrolled公告(): void {
		$pub_id      = $this->insert_announcement(
			[
				'post_title' => '公開',
				'meta_input' => [ 'visibility' => 'public' ],
			]
		);
		$enrolled_id = $this->insert_announcement(
			[
				'post_title' => '僅學員',
				'meta_input' => [ 'visibility' => 'enrolled' ],
			]
		);

		$list = Query::list_public( $this->course_id, $this->enrolled_user_id );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		$this->assertContains( $pub_id, $ids );
		$this->assertContains( $enrolled_id, $ids );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_公開列表_未購學員看不到enrolled公告(): void {
		$enrolled_id = $this->insert_announcement(
			[
				'post_title' => '僅學員',
				'meta_input' => [ 'visibility' => 'enrolled' ],
			]
		);

		$list = Query::list_public( $this->course_id, $this->guest_user_id );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		$this->assertNotContains( $enrolled_id, $ids );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_單一公告_含完整欄位(): void {
		$id = $this->insert_announcement(
			[
				'post_title'   => '測試公告',
				'post_content' => '<p>內容</p>',
				'meta_input'   => [
					'visibility' => 'public',
					'end_at'     => time() + DAY_IN_SECONDS,
				],
			]
		);
		$got = Query::get( $id );
		$this->assertNotNull( $got );
		$this->assertSame( '測試公告', $got['post_title'] );
		$this->assertSame( 'public', $got['visibility'] );
		$this->assertNotEmpty( $got['end_at'] );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_get_不存在公告回傳null(): void {
		$this->assertNull( Query::get( 99999 ) );
	}

	// ========== Draft 狀態支援（Issue: pc_announcement 三條改造） ==========

	/**
	 * 後台預設列表必須包含 draft（管理員需看到草稿）。
	 *
	 * @test
	 * @group edge
	 * @group draft
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Query::list
	 */
	public function test_list_default_includes_draft(): void {
		// Given: 同一課程下建立 publish / future / draft 各 1 篇
		$publish_id = $this->insert_announcement(
			[
				'post_title'  => '已發佈',
				'post_status' => 'publish',
			]
		);
		$future_id = $this->insert_announcement(
			[
				'post_title'  => '排程中',
				'post_status' => 'future',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$draft_id = $this->insert_announcement(
			[
				'post_title'  => '草稿',
				'post_status' => 'draft',
			]
		);

		// When: 呼叫 list（不帶 post_status 參數）
		$list = Query::list( [ 'parent_course_id' => $this->course_id ] );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		// Then: 三篇都應在內，draft 必須包含
		$this->assertCount( 3, $ids, '後台預設列表必須涵蓋 publish/future/draft 三種狀態' );
		$this->assertContains( $publish_id, $ids );
		$this->assertContains( $future_id, $ids );
		$this->assertContains( $draft_id, $ids, '後台 Query::list 預設必須包含 draft' );
	}

	/**
	 * 前台公開列表不可包含 draft（regression guard）。
	 *
	 * @test
	 * @group edge
	 * @group draft
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Query::list_public
	 */
	public function test_list_public_excludes_draft(): void {
		// Given: 同 T5 的資料設定（publish / future / draft 各 1 篇，皆 visibility=public）
		$publish_id = $this->insert_announcement(
			[
				'post_title'  => '已發佈',
				'post_status' => 'publish',
				'meta_input'  => [ 'visibility' => 'public' ],
			]
		);
		$future_id = $this->insert_announcement(
			[
				'post_title'  => '排程中',
				'post_status' => 'future',
				'post_date'   => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'meta_input'  => [ 'visibility' => 'public' ],
			]
		);
		$draft_id = $this->insert_announcement(
			[
				'post_title'  => '草稿',
				'post_status' => 'draft',
				'meta_input'  => [ 'visibility' => 'public' ],
			]
		);

		// When: 呼叫 list_public（前台用）
		$list = Query::list_public( $this->course_id, $this->guest_user_id );
		$ids  = array_map( fn( $a ) => (int) $a['id'], $list );

		// Then: 只含 publish；future / draft 都不可外洩到前台
		$this->assertContains( $publish_id, $ids, '前台應看到 publish 公告' );
		$this->assertNotContains( $future_id, $ids, '前台不可看到 future 公告' );
		$this->assertNotContains( $draft_id, $ids, '前台絕不可看到 draft 公告（資訊外洩風險）' );
	}
}
