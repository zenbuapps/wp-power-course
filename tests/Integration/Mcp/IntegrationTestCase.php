<?php
/**
 * MCP 整合測試基礎類別
 * 提供 MCP token fixtures 與 assertion helpers
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Migration;
use J7\PowerCourse\Api\Mcp\Auth;

/**
 * Class IntegrationTestCase
 * MCP 整合測試共用基礎類別
 */
abstract class IntegrationTestCase extends \Tests\Integration\TestCase {

	/**
	 * 測試用 token 明文（create_token 回傳的原始值）
	 *
	 * @var string|null
	 */
	protected ?string $test_token_plain = null;

	/**
	 * 測試用 token ID
	 *
	 * @var int|null
	 */
	protected ?int $test_token_id = null;

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();
		$this->ensure_mcp_tables_exist();
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		$this->clean_mcp_tables();
		parent::tear_down();
	}

	/**
	 * 確保 MCP 自訂資料表存在
	 * 每次 set_up 時呼叫，確保測試隔離後資料表仍存在（Migration::install() 使用 dbDelta 冪等）
	 */
	protected function ensure_mcp_tables_exist(): void {
		Migration::install();
	}

	/**
	 * 清理 MCP 資料表
	 */
	protected function clean_mcp_tables(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Migration::TOKENS_TABLE_NAME );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}" . Migration::ACTIVITY_TABLE_NAME );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ========== Token Fixtures ==========

	/**
	 * 建立測試用 MCP token
	 *
	 * @param int          $user_id     用戶 ID
	 * @param string       $name        token 名稱
	 * @param array<string> $capabilities 允許的 tool categories
	 * @return string token 明文（Bearer token 值）
	 */
	protected function create_test_token( int $user_id, string $name = 'Test Token', array $capabilities = [] ): string {
		$auth              = new Auth();
		$plain             = $auth->create_token( $user_id, $name, $capabilities );
		$this->test_token_plain = $plain;

		// 取得剛建立的 token ID
		global $wpdb;
		$hash                = hash( 'sha256', $plain );
		$row                 = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Migration::TOKENS_TABLE_NAME . ' WHERE token_hash = %s',
				$hash
			)
		);
		$this->test_token_id = $row ? (int) $row->id : null;

		return $plain;
	}

	// ========== Assertion Helpers ==========

	/**
	 * 斷言 token 具有指定 category 的權限
	 *
	 * @param string $token_plain token 明文
	 * @param string $category    category 名稱
	 */
	protected function assert_token_allows_category( string $token_plain, string $category ): void {
		$auth = new Auth();
		$user = $auth->verify_bearer_token( $token_plain );
		$this->assertNotFalse( $user, "Token 驗證失敗，無法取得用戶" );

		// 取得 token capabilities
		global $wpdb;
		$hash = hash( 'sha256', $token_plain );
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT capabilities FROM {$wpdb->prefix}" . Migration::TOKENS_TABLE_NAME . ' WHERE token_hash = %s',
				$hash
			)
		);
		$caps = $row && $row->capabilities ? json_decode( $row->capabilities, true ) : [];

		$this->assertTrue(
			empty( $caps ) || in_array( $category, (array) $caps, true ),
			"Token 不允許 category '{$category}'"
		);
	}

	/**
	 * 斷言 token 不具有指定 category 的權限
	 *
	 * @param string $token_plain token 明文
	 * @param string $category    category 名稱
	 */
	protected function assert_token_denies_category( string $token_plain, string $category ): void {
		global $wpdb;
		$hash = hash( 'sha256', $token_plain );
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT capabilities FROM {$wpdb->prefix}" . Migration::TOKENS_TABLE_NAME . ' WHERE token_hash = %s',
				$hash
			)
		);
		$caps = $row && $row->capabilities ? json_decode( $row->capabilities, true ) : [];

		$this->assertFalse(
			empty( $caps ) || in_array( $category, (array) $caps, true ),
			"Token 不應允許 category '{$category}'"
		);
	}

	/**
	 * 斷言 schema 陣列包含必要欄位
	 *
	 * @param array<string, mixed> $schema    JSON Schema 陣列
	 * @param string               $prop_name 屬性名稱
	 */
	protected function assert_schema_has_property( array $schema, string $prop_name ): void {
		$this->assertArrayHasKey( 'properties', $schema, "Schema 缺少 'properties' 鍵" );
		$this->assertArrayHasKey(
			$prop_name,
			$schema['properties'],
			"Schema properties 缺少 '{$prop_name}' 屬性"
		);
	}

	/**
	 * 斷言 permission 驗證失敗（current_user_can 為 false）
	 *
	 * @param callable $permission_fn permission callback
	 */
	protected function assert_permission_denied( callable $permission_fn ): void {
		$this->assertFalse( (bool) $permission_fn(), "Permission 應被拒絕，但實際上通過了" );
	}

	/**
	 * 斷言 permission 驗證通過
	 *
	 * @param callable $permission_fn permission callback
	 */
	protected function assert_permission_granted( callable $permission_fn ): void {
		$this->assertTrue( (bool) $permission_fn(), "Permission 應通過，但實際上被拒絕了" );
	}

	/**
	 * 建立具有 administrator 角色的用戶並設為當前用戶
	 *
	 * @return int 用戶 ID
	 */
	protected function create_admin_user(): int {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * 建立具有 subscriber 角色的用戶並設為當前用戶
	 *
	 * @return int 用戶 ID
	 */
	protected function create_subscriber_user(): int {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * 設為訪客（未登入）
	 */
	protected function set_guest_user(): void {
		wp_set_current_user( 0 );
	}

	/**
	 * 初始化依賴（MCP 測試不需要額外 repos/services）
	 */
	protected function configure_dependencies(): void {
		// MCP 測試通常不需要特定 repos/services，子類別視需要覆寫
	}

	// ========== WC Product Helpers（MCP tool 測試專用）==========

	/**
	 * 建立 WC 課程商品（透過 WC API 確保 wc_get_products 可查到）
	 *
	 * @param array<string, mixed> $args 覆蓋預設值
	 * @return int 商品 ID
	 */
	protected function create_wc_course( array $args = [] ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $args['post_title'] ?? '測試課程' );
		$product->set_status( $args['post_status'] ?? 'publish' );
		$product->set_regular_price( $args['price'] ?? '0' );
		$product->save();

		$course_id = $product->get_id();
		\update_post_meta( $course_id, '_is_course', $args['_is_course'] ?? 'yes' );
		\update_post_meta( $course_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );

		return $course_id;
	}

	/**
	 * 建立 WC Bundle 商品（透過 WC API）
	 *
	 * @param string $title     方案名稱
	 * @param int    $course_id 綁定課程 ID
	 * @return int 方案商品 ID
	 */
	protected function create_wc_bundle( string $title, int $course_id ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->set_regular_price( '0' );
		$product->save();

		$bundle_id = $product->get_id();
		\update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_id, \J7\PowerCourse\BundleProduct\Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );

		return $bundle_id;
	}

	/**
	 * 建立含 bundled product IDs 的 WC Bundle 商品
	 *
	 * @param int        $course_id   綁定課程 ID
	 * @param array<int> $product_ids 包含的商品 ID 列表
	 * @return int 方案商品 ID
	 */
	protected function create_wc_bundle_with_products( int $course_id, array $product_ids ): int {
		$bundle_id = $this->create_wc_bundle( '測試方案', $course_id );

		foreach ( $product_ids as $pid ) {
			\add_post_meta( $bundle_id, \J7\PowerCourse\BundleProduct\Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $pid );
		}

		$encoded = [];
		foreach ( $product_ids as $pid ) {
			$encoded[ (string) $pid ] = 1;
		}
		\update_post_meta( $bundle_id, \J7\PowerCourse\BundleProduct\Helper::PRODUCT_QUANTITIES_META_KEY, \wp_json_encode( $encoded ) );

		return $bundle_id;
	}
}
