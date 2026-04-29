<?php
/**
 * Announcement CPT 結構測試
 *
 * Feature: specs/features/announcement/公告CPT結構.feature
 * 測試 pc_announcement CPT 註冊、父子關係、post_status / post_date / end_at / visibility 行為。
 *
 * @group announcement
 * @group cpt
 * @group structure
 */

declare( strict_types=1 );

namespace Tests\Integration\Announcement;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Announcement\Core\CPT;

/**
 * Class AnnouncementCPTStructureTest
 */
class AnnouncementCPTStructureTest extends TestCase {

	/** @var int 課程 ID */
	private int $course_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress post API
	}

	public function set_up(): void {
		parent::set_up();
		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_pc_announcement_CPT已註冊(): void {
		$this->assertTrue( post_type_exists( CPT::POST_TYPE ), 'pc_announcement CPT 應已註冊' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_pc_announcement_為非階層式(): void {
		$obj = get_post_type_object( CPT::POST_TYPE );
		$this->assertNotNull( $obj );
		$this->assertFalse( $obj->hierarchical, 'pc_announcement 應為非階層式（hierarchical=false）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_pc_announcement_supports欄位(): void {
		$this->assertTrue( post_type_supports( CPT::POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( CPT::POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( CPT::POST_TYPE, 'custom-fields' ) );
		$this->assertTrue( post_type_supports( CPT::POST_TYPE, 'author' ) );
		$this->assertFalse(
			post_type_supports( CPT::POST_TYPE, 'page-attributes' ),
			'pc_announcement 不應支援 page-attributes（依時間排序）'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_pc_announcement_show_in_rest為true(): void {
		$obj = get_post_type_object( CPT::POST_TYPE );
		$this->assertTrue( $obj->show_in_rest );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 公告 post_parent 指向所屬課程
	 */
	public function test_公告post_parent指向課程(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '雙十一限時五折優惠！',
				'post_status' => 'publish',
				'post_parent' => $this->course_id,
			]
		);
		update_post_meta( $post_id, 'parent_course_id', $this->course_id );

		$post = get_post( $post_id );
		$this->assertSame( $this->course_id, (int) $post->post_parent );
		$this->assertSame( $this->course_id, (int) get_post_meta( $post_id, 'parent_course_id', true ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: post_status=future 為排程發佈，到期 wp_cron 自動轉 publish
	 */
	public function test_排程發佈公告post_status為future(): void {
		$future = '2099-11-03 09:00:00';
		$post_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '排程公告',
				'post_status' => 'future',
				'post_date'   => $future,
				'post_parent' => $this->course_id,
			]
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $future, get_post( $post_id )->post_date );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_可設定end_at_meta(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '限時公告',
				'post_status' => 'publish',
				'post_parent' => $this->course_id,
			]
		);
		update_post_meta( $post_id, 'end_at', 1762876800 );
		$this->assertSame( 1762876800, (int) get_post_meta( $post_id, 'end_at', true ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_visibility_meta_預設值與設定(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '公開公告',
				'post_status' => 'publish',
				'post_parent' => $this->course_id,
			]
		);
		update_post_meta( $post_id, 'visibility', 'public' );
		$this->assertSame( 'public', get_post_meta( $post_id, 'visibility', true ) );

		update_post_meta( $post_id, 'visibility', 'enrolled' );
		$this->assertSame( 'enrolled', get_post_meta( $post_id, 'visibility', true ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 公告依 post_date 由新到舊排序
	 */
	public function test_公告依post_date_DESC排序(): void {
		$ids = [];
		foreach (
			[
				[ '公告 A', '2026-04-01 10:00:00' ],
				[ '公告 B', '2026-04-15 10:00:00' ],
				[ '公告 C', '2026-04-20 10:00:00' ],
			] as $row
		) {
			$ids[] = $this->factory()->post->create(
				[
					'post_type'   => CPT::POST_TYPE,
					'post_title'  => $row[0],
					'post_status' => 'publish',
					'post_date'   => $row[1],
					'post_parent' => $this->course_id,
				]
			);
		}

		$query = new \WP_Query(
			[
				'post_type'      => CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_parent'    => $this->course_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);
		$result = array_map( 'intval', $query->posts );
		$this->assertSame( [ $ids[2], $ids[1], $ids[0] ], $result );
	}
}
