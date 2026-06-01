<?php
/**
 * 課程虛擬商品 Switch 整合測試 (Issue #237)
 *
 * 對應 specs/features/course/設定課程虛擬商品狀態.feature
 *
 * 覆蓋範圍：
 * - 課程儲存時，不再強制將 virtual 覆寫為 true（移除 hardcode）
 * - 未送 virtual key 時，DB 保持原值（向下相容既有合約）
 * - virtual=true / false / 字串等價值均能正確 round-trip 寫入 DB
 * - GET 課程詳情回應 virtual 欄位為 'yes' / 'no' 字串（既有合約）
 *
 * @group course
 * @group issue-237
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class CourseVirtualTest
 */
class CourseVirtualTest extends TestCase {

	/** @var CourseApi */
	private CourseApi $api;

	protected function configure_dependencies(): void {
		$this->api = CourseApi::instance();
	}

	/**
	 * 透過 reflection 呼叫 private handle_save_course_data
	 *
	 * @param \WC_Product          $product 產品物件
	 * @param array<string, mixed> $data 要更新的資料
	 * @return void
	 */
	private function invoke_handle_save_course_data( \WC_Product $product, array $data ): void {
		$reflection = new \ReflectionMethod( CourseApi::class, 'handle_save_course_data' );
		$reflection->setAccessible( true );
		$reflection->invoke( $this->api, $product, $data );
	}

	/**
	 * 建立 WC_Product_Simple 並設定 _is_course = yes、_virtual 為指定值
	 *
	 * @param string $virtual 'yes' 或 'no'
	 * @return \WC_Product_Simple
	 */
	private function create_wc_course_product( string $virtual ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( '虛擬商品測試課程' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '1200' );
		$product->set_virtual( 'yes' === $virtual );
		$product->save();

		\update_post_meta( $product->get_id(), '_is_course', 'yes' );

		return $product;
	}

	// ========== Rule: 課程儲存時不再強制覆寫 virtual ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 課程儲存時不再強制覆寫 virtual
	 * Example: 將虛擬課程改為實體課程，儲存後 DB 確實為 no
	 */
	public function test_儲存課程時virtual_false能正確寫入DB為no(): void {
		// Given 既有虛擬課程
		$product = $this->create_wc_course_product( 'yes' );
		$id      = $product->get_id();

		// When 送出 virtual=false
		$this->invoke_handle_save_course_data( $product, [ 'virtual' => false ] );

		// Then DB 中 _virtual 應為 'no'
		$this->assertSame(
			'no',
			\get_post_meta( $id, '_virtual', true ),
			'virtual=false 應將 _virtual meta 寫入 "no"，不再被硬寫為 true'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 課程儲存時 virtual 由 payload 決定
	 * Example: 將實體課程改為虛擬課程，儲存後 DB 確實為 yes
	 */
	public function test_儲存課程時virtual_true能正確寫入DB為yes(): void {
		// Given 既有實體課程
		$product = $this->create_wc_course_product( 'no' );
		$id      = $product->get_id();

		// When 送出 virtual=true
		$this->invoke_handle_save_course_data( $product, [ 'virtual' => true ] );

		// Then DB 中 _virtual 應為 'yes'
		$this->assertSame(
			'yes',
			\get_post_meta( $id, '_virtual', true ),
			'virtual=true 應將 _virtual meta 寫入 "yes"'
		);
	}

	// ========== Rule: virtual 未出現在 request body 時，視為「保持原狀」 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（參數）- virtual 未出現在 request body 時，視為「保持原狀」（向下相容）
	 * Example: 既有虛擬課程更新 name 但未送 virtual，virtual 保留為 yes
	 */
	public function test_未送virtual_key時既有虛擬課程DB保持yes(): void {
		// Given 既有虛擬課程
		$product = $this->create_wc_course_product( 'yes' );
		$id      = $product->get_id();

		// When 只送 name，沒有 virtual key
		$this->invoke_handle_save_course_data( $product, [ 'name' => '新名稱' ] );

		// Then DB 中 _virtual 仍應為 'yes'（保持原狀）
		$this->assertSame(
			'yes',
			\get_post_meta( $id, '_virtual', true ),
			'未送 virtual key 時，DB 應保留原值 yes（向下相容）'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（參數）- virtual 未出現在 request body 時，視為「保持原狀」
	 * Example: 既有實體課程更新 name 但未送 virtual，virtual 保留為 no
	 */
	public function test_未送virtual_key時既有實體課程DB保持no(): void {
		// Given 既有實體課程
		$product = $this->create_wc_course_product( 'no' );
		$id      = $product->get_id();

		// When 只送 name，沒有 virtual key
		$this->invoke_handle_save_course_data( $product, [ 'name' => '新名稱' ] );

		// Then DB 中 _virtual 仍應為 'no'（不再被硬寫為 true）
		$this->assertSame(
			'no',
			\get_post_meta( $id, '_virtual', true ),
			'未送 virtual key 時，DB 應保留原值 no（不再強制覆寫為 true）'
		);
	}

	// ========== Rule: GET 課程詳情回應 virtual 為 'yes' / 'no' 字串 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- GET 課程詳情回應內含 virtual 欄位
	 * Example: 既有虛擬課程 GET 回應 virtual 為 "yes"
	 */
	public function test_GET課程詳情虛擬課程回應virtual欄位為yes(): void {
		// Given 既有虛擬課程
		$product = $this->create_wc_course_product( 'yes' );

		// When 呼叫 format_course_base_records
		$formatted = $this->api->format_course_base_records( $product );

		// Then 'virtual' 應為字串 'yes'
		$this->assertArrayHasKey( 'virtual', $formatted, 'GET 回應應包含 virtual 欄位' );
		$this->assertSame(
			'yes',
			$formatted['virtual'],
			'虛擬課程 GET 回應 virtual 欄位應為字串 "yes"（與 manage_stock 等欄位一致使用 wc_bool_to_string）'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- GET 課程詳情回應內含 virtual 欄位
	 * Example: 實體課程 GET 回應 virtual 為 "no"
	 */
	public function test_GET課程詳情實體課程回應virtual欄位為no(): void {
		// Given 既有實體課程
		$product = $this->create_wc_course_product( 'no' );

		// When 呼叫 format_course_base_records
		$formatted = $this->api->format_course_base_records( $product );

		// Then 'virtual' 應為字串 'no'
		$this->assertArrayHasKey( 'virtual', $formatted, 'GET 回應應包含 virtual 欄位' );
		$this->assertSame(
			'no',
			$formatted['virtual'],
			'實體課程 GET 回應 virtual 欄位應為字串 "no"'
		);
	}

	// ========== Rule: virtual 接受字串等價值（Scenario Outline）==========

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_virtual_equivalent_values
	 *
	 * Rule: 前置（參數）- virtual 接受 boolean 與其等價值
	 *
	 * @param mixed  $input       request payload 的 virtual 值
	 * @param string $expected_db 預期 DB 中 _virtual meta
	 */
	public function test_儲存課程時virtual接受字串等價值( $input, string $expected_db ): void {
		// Given 既有實體課程（從 no 起跳，方便看 yes 也能寫入）
		$product = $this->create_wc_course_product( 'no' );
		$id      = $product->get_id();

		// When 送出 virtual=<input>
		$this->invoke_handle_save_course_data( $product, [ 'virtual' => $input ] );

		// Then DB 中 _virtual 應為 expected_db
		$this->assertSame(
			$expected_db,
			\get_post_meta( $id, '_virtual', true ),
			sprintf( 'virtual=%s 應寫入 _virtual=%s', var_export( $input, true ), $expected_db )
		);
	}

	/**
	 * Data provider: virtual 等價值映射表
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provide_virtual_equivalent_values(): array {
		return [
			'布林 true'      => [ true, 'yes' ],
			'布林 false'     => [ false, 'no' ],
			'字串 "true"'    => [ 'true', 'yes' ],
			'字串 "false"'   => [ 'false', 'no' ],
			'字串 "yes"'     => [ 'yes', 'yes' ],
			'字串 "no"'      => [ 'no', 'no' ],
		];
	}

	// ========== Rule: virtual=false 不聯動其他欄位 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- virtual 切換為 false 時，僅異動 `_virtual` meta，
	 * 不聯動 `_downloadable` / `_manage_stock` / shipping 相關欄位
	 */
	public function test_virtual_false不影響downloadable與manage_stock(): void {
		// Given 既有虛擬課程，且 downloadable=false、manage_stock=false
		$product = $this->create_wc_course_product( 'yes' );
		$product->set_downloadable( false );
		$product->set_manage_stock( false );
		$product->save();

		$id = $product->get_id();
		$this->assertSame( 'no', \get_post_meta( $id, '_downloadable', true ) );
		$this->assertSame( 'no', \get_post_meta( $id, '_manage_stock', true ) );

		// When 只切 virtual=false
		$this->invoke_handle_save_course_data( $product, [ 'virtual' => false ] );

		// Then virtual 變更但 downloadable / manage_stock 不變
		$this->assertSame( 'no', \get_post_meta( $id, '_virtual', true ), 'virtual 應為 no' );
		$this->assertSame(
			'no',
			\get_post_meta( $id, '_downloadable', true ),
			'切 virtual 不應聯動 downloadable'
		);
		$this->assertSame(
			'no',
			\get_post_meta( $id, '_manage_stock', true ),
			'切 virtual 不應聯動 manage_stock'
		);
	}
}
