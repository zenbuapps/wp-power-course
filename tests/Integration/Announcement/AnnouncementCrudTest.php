<?php
/**
 * Announcement CRUD 業務邏輯測試
 *
 * Feature: specs/features/announcement/建立公告.feature、更新公告.feature、刪除公告.feature
 *
 * @group announcement
 * @group crud
 */

declare( strict_types=1 );

namespace Tests\Integration\Announcement;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Announcement\Core\CPT;
use J7\PowerCourse\Resources\Announcement\Service\Crud;

/**
 * Class AnnouncementCrudTest
 */
class AnnouncementCrudTest extends TestCase {

	/** @var int 課程 ID */
	private int $course_id;

	/** @var int 外部課程 ID */
	private int $external_course_id;

	/** @var int 非課程商品 ID */
	private int $non_course_product_id;

	protected function configure_dependencies(): void {
		// 使用 Service\Crud
	}

	public function set_up(): void {
		parent::set_up();
		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->external_course_id = $this->create_course( [ 'post_title' => '外部課程' ] );
		// 非課程商品：post_type=product，但 _is_course=no
		$this->non_course_product_id = $this->factory()->post->create(
			[
				'post_type'  => 'product',
				'post_title' => '一般商品',
			]
		);
		update_post_meta( $this->non_course_product_id, '_is_course', 'no' );

		wp_set_current_user(
			$this->factory()->user->create( [ 'role' => 'administrator' ] )
		);
	}

	// ========== 建立 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立成功_立即發佈無結束時間(): void {
		$id = Crud::create(
			[
				'post_title'       => '第五章全新上線！',
				'post_content'     => '<p>歡迎來學習新內容</p>',
				'parent_course_id' => $this->course_id,
				'post_status'      => 'publish',
				'visibility'       => 'public',
			]
		);
		$post = get_post( $id );
		$this->assertSame( CPT::POST_TYPE, $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $this->course_id, (int) $post->post_parent );
		$this->assertSame( $this->course_id, (int) get_post_meta( $id, 'parent_course_id', true ) );
		$this->assertSame( 'public', get_post_meta( $id, 'visibility', true ) );
		$this->assertSame( '', get_post_meta( $id, 'end_at', true ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立成功_含結束時間(): void {
		$end_at = (int) ( time() + 7 * DAY_IN_SECONDS );
		$id     = Crud::create(
			[
				'post_title'       => '雙十一限時五折優惠',
				'parent_course_id' => $this->course_id,
				'post_status'      => 'publish',
				'visibility'       => 'public',
				'end_at'           => $end_at,
			]
		);
		$this->assertSame( $end_at, (int) get_post_meta( $id, 'end_at', true ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立成功_預約發佈(): void {
		$future = wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$id     = Crud::create(
			[
				'post_title'       => '下週發佈',
				'parent_course_id' => $this->course_id,
				'post_status'      => 'future',
				'post_date'        => $future,
			]
		);
		$this->assertSame( 'future', get_post_status( $id ) );
		$this->assertSame( $future, get_post( $id )->post_date );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立成功_僅學員可見(): void {
		$id = Crud::create(
			[
				'post_title'       => '內部更新通知',
				'parent_course_id' => $this->course_id,
				'visibility'       => 'enrolled',
			]
		);
		$this->assertSame( 'enrolled', get_post_meta( $id, 'visibility', true ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立成功_外部課程也可建立公告(): void {
		$id = Crud::create(
			[
				'post_title'       => '合作推廣公告',
				'parent_course_id' => $this->external_course_id,
				'post_status'      => 'publish',
			]
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( $this->external_course_id, (int) get_post( $id )->post_parent );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_post_title為空(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'post_title' );
		Crud::create(
			[
				'post_title'       => '',
				'parent_course_id' => $this->course_id,
			]
		);
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_parent_course_id為空(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'parent_course_id' );
		Crud::create( [ 'post_title' => '公告' ] );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_parent_course_id為非課程商品(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'parent_course_id' );
		Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->non_course_product_id,
			]
		);
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_visibility非法(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'visibility' );
		Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
				'visibility'       => 'invalid',
			]
		);
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_end_at位數不足(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'end_at' );
		Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
				'end_at'           => '12345',
			]
		);
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_end_at為負數(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'end_at' );
		Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
				'end_at'           => -1762876800,
			]
		);
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_建立失敗_end_at早於post_date(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'end_at' );
		Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
				'post_date'        => '2099-12-01 00:00:00',
				'end_at'           => 1762876800,
			]
		);
	}

	// ========== 更新 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_更新成功_標題與內容(): void {
		$id = Crud::create(
			[
				'post_title'       => 'Old',
				'parent_course_id' => $this->course_id,
			]
		);
		Crud::update(
			$id,
			[
				'post_title'   => '雙十一限時五折優惠',
				'post_content' => '<p>使用折扣碼 SAVE50</p>',
			]
		);
		$post = get_post( $id );
		$this->assertSame( '雙十一限時五折優惠', $post->post_title );
		$this->assertStringContainsString( 'SAVE50', $post->post_content );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_更新成功_清除end_at(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
				'end_at'           => time() + DAY_IN_SECONDS,
			]
		);
		Crud::update( $id, [ 'end_at' => '' ] );
		$this->assertSame( '', get_post_meta( $id, 'end_at', true ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_更新成功_修改可見性(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		Crud::update( $id, [ 'visibility' => 'enrolled' ] );
		$this->assertSame( 'enrolled', get_post_meta( $id, 'visibility', true ) );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_更新失敗_公告不存在(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '公告不存在' );
		Crud::update( 99999, [ 'post_title' => '新標題' ] );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_更新失敗_visibility非法(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'visibility' );
		Crud::update( $id, [ 'visibility' => 'invalid' ] );
	}

	// ========== 刪除 / 還原 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_刪除成功_軟刪除(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		$this->assertTrue( Crud::delete( $id ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_刪除冪等_對已trash公告再次刪除視為成功(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		Crud::delete( $id );
		// 第二次刪除應仍視為成功
		$this->assertTrue( Crud::delete( $id ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_刪除失敗_公告不存在(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '公告不存在' );
		Crud::delete( 99999 );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_批次刪除失敗_ids為空陣列(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ids' );
		Crud::delete_many( [] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_批次刪除成功(): void {
		$id1 = Crud::create(
			[
				'post_title'       => 'A',
				'parent_course_id' => $this->course_id,
			]
		);
		$id2 = Crud::create(
			[
				'post_title'       => 'B',
				'parent_course_id' => $this->course_id,
			]
		);
		$result = Crud::delete_many( [ $id1, $id2 ] );
		$this->assertContains( $id1, $result['success'] );
		$this->assertContains( $id2, $result['success'] );
		$this->assertSame( 'trash', get_post_status( $id1 ) );
		$this->assertSame( 'trash', get_post_status( $id2 ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_還原成功(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		Crud::delete( $id );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertTrue( Crud::restore( $id ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_永久刪除(): void {
		$id = Crud::create(
			[
				'post_title'       => '公告',
				'parent_course_id' => $this->course_id,
			]
		);
		$this->assertTrue( Crud::delete( $id, true ) );
		$this->assertNull( get_post( $id ) );
	}

	// ========== Draft 狀態支援（Issue: pc_announcement 三條改造） ==========

	/**
	 * 建立草稿 + 未來日期，post_status 應保留 'draft'，不被 normalize 改成 'future'。
	 *
	 * @test
	 * @group edge
	 * @group draft
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Crud::create
	 */
	public function test_create_with_draft_status_keeps_draft_regardless_of_date(): void {
		// Given: 一筆 post_status='draft' 且 post_date 為未來時間的公告 payload
		$future_date = wp_date( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );

		// When: 呼叫 Crud::create
		$id = Crud::create(
			[
				'post_title'       => 'Test Draft',
				'post_status'      => 'draft',
				'post_date'        => $future_date,
				'parent_course_id' => $this->course_id,
			]
		);

		// Then: 寫回 DB 的 post_status 仍為 'draft'，不被 normalize 改成 'future'
		$post = get_post( $id );
		$this->assertNotNull( $post, '建立後應可取得 post' );
		$this->assertSame( 'draft', $post->post_status, 'draft 狀態必須保留，不可被 normalize 改成 future' );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	/**
	 * Draft + 過去日期：normalize_status_and_date 應 return early，不轉成 publish。
	 *
	 * @test
	 * @group edge
	 * @group draft
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Crud::create
	 */
	public function test_normalize_returns_early_when_status_is_draft(): void {
		// Given: post_status='draft' + post_date 為過去時間
		$past_date = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// When: 呼叫 Crud::create（內部會跑 normalize_status_and_date）
		$id = Crud::create(
			[
				'post_title'       => '草稿公告',
				'post_status'      => 'draft',
				'post_date'        => $past_date,
				'parent_course_id' => $this->course_id,
			]
		);

		// Then: post_status 仍為 'draft'，不轉 publish
		$post = get_post( $id );
		$this->assertNotNull( $post );
		$this->assertSame( 'draft', $post->post_status, 'draft + 過去日期不可被 normalize 改成 publish' );

		// Then: post_date 也保留原值（不被 normalize 改寫）
		$this->assertSame(
			$past_date,
			$post->post_date,
			'draft 狀態下 post_date 必須保留原值，不被 normalize 改寫'
		);
	}

	/**
	 * 還原 draft 公告：應保留 'draft' 狀態，不被覆蓋為 'publish'（修正既有 bug 的核心 case）。
	 *
	 * @test
	 * @group edge
	 * @group draft
	 * @group restore
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Crud::restore
	 */
	public function test_restore_preserves_draft_status(): void {
		// Given: 一篇 post_status='draft' 的公告
		$id = Crud::create(
			[
				'post_title'       => '草稿公告',
				'post_status'      => 'draft',
				'parent_course_id' => $this->course_id,
			]
		);
		$this->assertSame( 'draft', get_post_status( $id ), '前置條件：建立應為 draft' );

		// Given: 軟刪除進垃圾桶（force=false）
		$this->assertTrue( Crud::delete( $id, false ) );
		$this->assertSame( 'trash', get_post_status( $id ), '前置條件：trash 後狀態為 trash' );

		// Given: WordPress 的 wp_trash_post 會把原狀態存進 _wp_desired_post_status
		$desired = get_post_meta( $id, '_wp_desired_post_status', true );
		$this->assertSame(
			'draft',
			$desired,
			'前置條件：trash 前的原狀態應為 draft（記錄於 _wp_desired_post_status）'
		);

		// When: 還原
		$this->assertTrue( Crud::restore( $id ) );

		// Then: 還原後仍為 draft，不被強制覆蓋成 publish
		$this->assertSame(
			'draft',
			get_post_status( $id ),
			'restore() 必須尊重 _wp_desired_post_status=draft，不可硬寫 publish'
		);
	}

	/**
	 * 還原 future 公告但日期已過期：經 normalize 後應變成 publish。
	 *
	 * @test
	 * @group edge
	 * @group restore
	 * @covers \J7\PowerCourse\Resources\Announcement\Service\Crud::restore
	 */
	public function test_restore_future_post_with_past_date_becomes_publish_via_normalize(): void {
		// Given: 一篇 future 公告（post_date 為未來）
		$future_date = wp_date( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$id          = Crud::create(
			[
				'post_title'       => '排程公告',
				'post_status'      => 'future',
				'post_date'        => $future_date,
				'parent_course_id' => $this->course_id,
			]
		);
		$this->assertSame( 'future', get_post_status( $id ), '前置條件：建立應為 future' );

		// Given: 軟刪除進垃圾桶
		$this->assertTrue( Crud::delete( $id, false ) );
		$this->assertSame( 'trash', get_post_status( $id ) );

		// Given: 模擬 trash 期間時間流逝—直接以 SQL 把 post_date 改成過去（避開 wp_update_post 的副作用）
		global $wpdb;
		$past_date = '2020-01-01 00:00:00';
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_date'     => $past_date,
				'post_date_gmt' => $past_date,
			],
			[ 'ID' => $id ]
		);
		clean_post_cache( $id );
		$this->assertSame( $past_date, get_post( $id )->post_date, '前置條件：post_date 應已被改成過去' );

		// When: 還原
		$this->assertTrue( Crud::restore( $id ) );

		// Then: normalize_status_and_date 偵測 future + 過期 date → 應補正為 publish
		$this->assertSame(
			'publish',
			get_post_status( $id ),
			'restore() 後若 post_date 已過期，normalize 應將 future 補正為 publish'
		);
	}
}
