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
}
