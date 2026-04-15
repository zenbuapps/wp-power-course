<?php
/**
 * MCP Student Update Meta Tool
 *
 * 更新學員 user meta。**高風險**操作，因此：
 * 1. 嚴格限制可寫入的 meta_key 白名單（ALLOWED_META_KEYS）
 * 2. 黑名單優先：任何敏感 meta（wp_capabilities / session_tokens / user_pass / user_level / is_teacher / is_admin）一律拒絕
 * 3. input schema 的 meta_key 以 enum 明示白名單，LLM 只能選擇合法值
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;

/**
 * Class StudentUpdateMetaTool
 *
 * 對應 MCP ability：power-course/student_update_meta
 */
final class StudentUpdateMetaTool extends AbstractTool {

	/**
	 * 允許寫入的 meta key 白名單
	 * 僅開放「個人基本資料」層級的欄位，禁止任何涉及權限/session/認證的 key
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_META_KEYS = [
		'first_name',
		'last_name',
		'billing_first_name',
		'billing_last_name',
		'billing_phone',
		'billing_email',
		'description',
	];

	/**
	 * 禁止寫入的 meta key 黑名單（即使 key 名稱在白名單中，含以下 pattern 一律拒絕）
	 * 黑名單優先於白名單
	 *
	 * @var array<int, string>
	 */
	public const DENIED_META_KEY_PATTERNS = [
		'wp_capabilities',
		'capabilities',
		'session_tokens',
		'user_pass',
		'user_level',
		'wp_user_level',
		'is_teacher',
		'is_admin',
		'role',
	];

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_update_meta';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '更新學員 meta', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '更新指定學員的 user_meta（僅允許預先定義的白名單欄位，禁止寫入權限或敏感欄位）。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '學員 ID。', 'power-course' ),
				],
				'meta_key'   => [
					'type'        => 'string',
					'enum'        => self::ALLOWED_META_KEYS,
					'description' => \__( '要更新的 meta key（限白名單）。', 'power-course' ),
				],
				'meta_value' => [
					'type'        => 'string',
					'description' => \__( '新的 meta 值（會以 sanitize_text_field 清理）。', 'power-course' ),
				],
			],
			'required'   => [ 'user_id', 'meta_key', 'meta_value' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'    => [ 'type' => 'integer' ],
				'meta_key'   => [ 'type' => 'string' ],
				'meta_value' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'edit_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'student';
	}

	/**
	 * 判斷 meta_key 是否允許寫入
	 * 黑名單優先：任一黑名單 pattern 出現於 meta_key 即拒絕
	 * 接著白名單檢查：必須完全符合白名單中某個 key
	 *
	 * @param string $meta_key 待檢查的 meta key
	 * @return bool
	 */
	public static function is_meta_key_allowed( string $meta_key ): bool {
		$meta_key_lower = strtolower( $meta_key );

		foreach ( self::DENIED_META_KEY_PATTERNS as $denied ) {
			if ( str_contains( $meta_key_lower, $denied ) ) {
				return false;
			}
		}

		return in_array( $meta_key, self::ALLOWED_META_KEYS, true );
	}

	/**
	 * 執行更新 meta
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{user_id: int, meta_key: string, meta_value: string}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$user_id    = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$meta_key   = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : '';
		$meta_value = isset( $args['meta_value'] ) ? (string) $args['meta_value'] : '';

		if ( $user_id <= 0 || '' === $meta_key ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id 與 meta_key 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! self::is_meta_key_allowed( $meta_key ) ) {
			// 嘗試寫入不允許的 key：記錄 + 拒絕
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				"meta_key '{$meta_key}' is not allowed",
				false
			);

			return new \WP_Error(
				'mcp_meta_key_forbidden',
				\sprintf(
					/* translators: %s: 嘗試寫入的 meta key */
					\__( 'meta_key "%s" 不在允許清單中，禁止寫入（防權限提升）。', 'power-course' ),
					$meta_key
				),
				[ 'status' => 403 ]
			);
		}

		if ( ! \get_user_by( 'id', $user_id ) ) {
			return new \WP_Error(
				'mcp_user_not_found',
				\__( '找不到指定的學員。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$clean_value = \sanitize_text_field( $meta_value );
		$updated     = \update_user_meta( $user_id, $meta_key, $clean_value );

		if ( false === $updated ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				'update_user_meta returned false',
				false
			);
			return new \WP_Error(
				'mcp_update_meta_failed',
				\__( '更新 meta 失敗。', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'user_id'    => $user_id,
			'meta_key'   => $meta_key,
			'meta_value' => $clean_value,
		];

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			true
		);

		return $result;
	}
}
