<?php
/**
 * 課程描述跳脫字元儲存整合測試
 *
 * Feature: JSON parse error 根治（JSON_PARSE_ERROR.md）
 * 測試 Course Api handle_save_course_data / handle_save_course_meta_data 的寫入對稱性：
 * - description（Power 編輯器 HTML，經 WC data store → wp_update_post）含反斜線 byte 不變
 * - meta（如 trial_videos JSON）含跳脫字元 byte 不變
 *
 * @group course
 * @group json-content
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class CourseDescriptionSlashTest
 *
 * @group json-content
 */
class CourseDescriptionSlashTest extends TestCase {

	/** @var CourseApi API 實例 */
	private CourseApi $api;

	/** @var string 含反斜線與引號的編輯器 HTML */
	private string $html_with_escapes = '<div class="power-editor"><pre><code>echo "C:\\\\Users\\\\test"; // \\n 換行</code></pre></div>';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/**
	 * 每個測試前以管理員身分執行
	 */
	public function set_up(): void {
		parent::set_up();
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
		$this->api = CourseApi::instance();
	}

	/**
	 * 反射呼叫 private handle_save_course_data
	 *
	 * @param \WC_Product          $product 商品
	 * @param array<string, mixed> $data    資料
	 */
	private function invoke_handle_save_course_data( \WC_Product $product, array $data ): void {
		$reflection = new \ReflectionMethod( CourseApi::class, 'handle_save_course_data' );
		$reflection->setAccessible( true );
		$reflection->invoke( $this->api, $product, $data );
	}

	/**
	 * 反射呼叫 private handle_save_course_meta_data
	 *
	 * @param \WC_Product          $product   商品
	 * @param array<string, mixed> $meta_data meta 資料
	 * @return \WP_Error|bool
	 */
	private function invoke_handle_save_course_meta_data( \WC_Product $product, array $meta_data ): \WP_Error|bool {
		$reflection = new \ReflectionMethod( CourseApi::class, 'handle_save_course_meta_data' );
		$reflection->setAccessible( true );
		return $reflection->invoke( $this->api, $product, $meta_data );
	}

	/** @return \WC_Product_Simple 建立測試課程商品 */
	private function create_course_product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( '跳脫字元測試課程' );
		$product->set_status( 'publish' );
		$product->save();
		\update_post_meta( $product->get_id(), '_is_course', 'yes' );
		return $product;
	}

	/** @return string 直接從 DB 讀 post_content */
	private function get_db_content( int $post_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: description 含反斜線，經 WC data store 儲存後 byte 不變
	 */
	public function test_description含反斜線儲存後byte不變(): void {
		$product = $this->create_course_product();

		$this->invoke_handle_save_course_data( $product, [ 'description' => $this->html_with_escapes ] );

		$this->assertSame(
			$this->html_with_escapes,
			$this->get_db_content( $product->get_id() ),
			'description 儲存後必須與傳入內容完全一致'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 重複儲存不劣化——同一份 description 存三次 byte 仍不變
	 */
	public function test_重複儲存description不劣化(): void {
		$product = $this->create_course_product();

		for ( $i = 0; $i < 3; $i++ ) {
			$current = 0 === $i ? $this->html_with_escapes : $this->get_db_content( $product->get_id() );
			// 每輪重新取得 product 實例，模擬每次獨立的 REST 請求
			$fresh = \wc_get_product( $product->get_id() );
			$this->invoke_handle_save_course_data( $fresh, [ 'description' => $current ] );
		}

		$this->assertSame(
			$this->html_with_escapes,
			$this->get_db_content( $product->get_id() ),
			'重複儲存三次後 description 不得遺失任何字元'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 自訂 meta 含跳脫字元字串，儲存後讀回 byte 不變
	 */
	public function test_meta含跳脫字元儲存後byte不變(): void {
		$product = $this->create_course_product();
		$value   = "meta value with \\ backslash and \"quotes\"";

		// 第一次儲存走 WC add_meta 路徑
		$result = $this->invoke_handle_save_course_meta_data( $product, [ 'custom_note' => $value ] );

		$this->assertTrue( $result );
		$this->assertSame(
			$value,
			\get_post_meta( $product->get_id(), 'custom_note', true ),
			'meta 字串儲存後（add 路徑）必須與傳入內容完全一致'
		);

		// 第二次儲存走 WC update_metadata_by_mid 路徑，兩條路徑的 slash 行為都要對稱
		$fresh  = \wc_get_product( $product->get_id() );
		$result = $this->invoke_handle_save_course_meta_data( $fresh, [ 'custom_note' => $value ] );

		$this->assertTrue( $result );
		$this->assertSame(
			$value,
			\get_post_meta( $product->get_id(), 'custom_note', true ),
			'meta 字串重複儲存後（update 路徑）必須與傳入內容完全一致'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: trial_videos JSON meta 含引號標題，儲存後仍為合法 JSON 且內容不變
	 */
	public function test_trial_videos含引號標題儲存後仍為合法JSON(): void {
		$product = $this->create_course_product();
		$videos  = [
			[
				'type' => 'youtube',
				'id'   => 'abc',
				'meta' => [ 'title' => 'He said "hello" \\ world' ],
			],
		];

		$result = $this->invoke_handle_save_course_meta_data( $product, [ 'trial_videos' => $videos ] );

		$this->assertTrue( $result );

		$stored = (string) \get_post_meta( $product->get_id(), 'trial_videos', true );
		$decoded = json_decode( $stored, true );

		$this->assertNotNull( $decoded, 'trial_videos 必須是合法 JSON：' . $stored );
		$this->assertSame( 'He said "hello" \\ world', $decoded[0]['meta']['title'] ?? null );
	}
}
