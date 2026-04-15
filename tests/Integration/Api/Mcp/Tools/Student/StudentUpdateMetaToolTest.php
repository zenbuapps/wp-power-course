<?php
/**
 * StudentUpdateMetaTool 整合測試（重點：Meta whitelist 安全機制）
 *
 * @group mcp
 * @group student
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentUpdateMetaTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentUpdateMetaToolTest
 */
class StudentUpdateMetaToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員可更新白名單內的 meta
	 *
	 * @group happy
	 */
	public function test_admin_can_update_whitelisted_meta(): void {
		$this->create_admin_user();
		$target_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentUpdateMetaTool();
		$result = $tool->run(
			[
				'user_id'    => $target_user,
				'meta_key'   => 'first_name',
				'meta_value' => 'NewFirstName',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'NewFirstName', \get_user_meta( $target_user, 'first_name', true ) );
	}

	/**
	 * 安全：嘗試寫入 wp_capabilities 必須被拒
	 *
	 * @group security
	 */
	public function test_cannot_update_wp_capabilities(): void {
		$this->create_admin_user();
		$target_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentUpdateMetaTool();
		$result = $tool->run(
			[
				'user_id'    => $target_user,
				'meta_key'   => 'wp_capabilities',
				'meta_value' => 'a:1:{s:13:"administrator";b:1;}',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_meta_key_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );

		// 驗證 target_user 仍為 subscriber
		$user = \get_user_by( 'id', $target_user );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertNotContains( 'administrator', $user->roles );
	}

	/**
	 * 安全：嘗試寫入 session_tokens 必須被拒
	 *
	 * @group security
	 */
	public function test_cannot_update_session_tokens(): void {
		$this->create_admin_user();
		$target_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentUpdateMetaTool();
		$result = $tool->run(
			[
				'user_id'    => $target_user,
				'meta_key'   => 'session_tokens',
				'meta_value' => 'evil',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_meta_key_forbidden', $result->get_error_code() );
	}

	/**
	 * 安全：嘗試寫入 is_teacher / is_admin 等敏感 key 必須被拒
	 *
	 * @group security
	 * @dataProvider provide_forbidden_meta_keys
	 *
	 * @param string $meta_key 嘗試寫入的 meta key
	 */
	public function test_cannot_update_forbidden_keys( string $meta_key ): void {
		$this->create_admin_user();
		$target_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentUpdateMetaTool();
		$result = $tool->run(
			[
				'user_id'    => $target_user,
				'meta_key'   => $meta_key,
				'meta_value' => 'yes',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_meta_key_forbidden', $result->get_error_code() );
	}

	/**
	 * 提供被黑名單攔截的 meta keys
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_forbidden_meta_keys(): array {
		return [
			'wp_user_level'        => [ 'wp_user_level' ],
			'user_level'           => [ 'user_level' ],
			'is_teacher'           => [ 'is_teacher' ],
			'is_admin'             => [ 'is_admin' ],
			'role'                 => [ 'role' ],
			'arbitrary_other_meta' => [ 'arbitrary_key_not_in_whitelist' ],
		];
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new StudentUpdateMetaTool();
		$result = $tool->run(
			[
				'user_id'    => 1,
				'meta_key'   => 'first_name',
				'meta_value' => 'x',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * Schema 的 meta_key enum 必須僅包含白名單
	 *
	 * @group smoke
	 */
	public function test_schema_enum_matches_whitelist(): void {
		$tool   = new StudentUpdateMetaTool();
		$schema = $tool->get_input_schema();

		$this->assertArrayHasKey( 'meta_key', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['meta_key'] );
		$this->assertSame( StudentUpdateMetaTool::ALLOWED_META_KEYS, $schema['properties']['meta_key']['enum'] );
	}
}
