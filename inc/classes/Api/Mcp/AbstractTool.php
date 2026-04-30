<?php
/**
 * MCP AbstractTool — 所有 MCP tool 的抽象基礎類別
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class AbstractTool (abstract)
 * 定義 MCP tool 的必要介面與統一的 permission/ability 行為
 * 所有具體 tool 必須繼承此類別
 *
 * 核心規範：
 * - execute() 只應在 permission_callback() 通過後被呼叫
 * - run() 為統一入口，內部強制 permission 檢查與操作權限檢查
 * - 每個 tool 的 ability 名稱格式為 power-course/{name}
 * - `pc_mcp_settings` 中的 `allow_update` / `allow_delete` 欄位控制寫入與刪除權限，
 *   兩者預設為 false（唯讀模式）；站長必須在 WordPress Admin → Power Course → 設定 → AI 啟用
 */
abstract class AbstractTool {

	/** ability 名稱前綴，符合計畫規格 */
	const ABILITY_PREFIX = 'power-course/';

	/** 操作類型常數 */
	const OP_READ   = 'read';
	const OP_UPDATE = 'update';
	const OP_DELETE = 'delete';

	/**
	 * 回傳 tool 的簡短名稱（不含前綴）
	 * 例：'course_list'
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * 回傳 tool 的人類可讀標籤
	 *
	 * @return string
	 */
	abstract public function get_label(): string;

	/**
	 * 回傳 tool 的描述文字
	 *
	 * @return string
	 */
	abstract public function get_description(): string;

	/**
	 * 回傳 tool 的輸入 JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	abstract public function get_input_schema(): array;

	/**
	 * 回傳 tool 的輸出 JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	abstract public function get_output_schema(): array;

	/**
	 * 回傳執行此 tool 所需的 WordPress capability
	 * 例：'manage_woocommerce'、'edit_posts'
	 *
	 * @return string
	 */
	abstract public function get_capability(): string;

	/**
	 * 回傳此 tool 所屬的 category
	 * 對應 Settings 中的 enabled_categories 值
	 * 例：'course'、'chapter'、'student'
	 *
	 * @return string
	 */
	abstract public function get_category(): string;

	/**
	 * 執行 tool 的業務邏輯
	 * 子類別實作，不應直接呼叫，請改用 run()
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return mixed
	 */
	abstract protected function execute( array $args ): mixed;

	/**
	 * 取得此 tool 的操作類型（read / update / delete）
	 * 預設根據 tool name 自動推導，子類別可覆寫
	 *
	 * 推導規則：
	 * - 含 _delete / _remove / _reset → OP_DELETE
	 * - 含 _list / _get / _export / _stats / _count → OP_READ
	 * - 其餘（create / update / sort / toggle / set / assign / add / mark / grant / duplicate）→ OP_UPDATE
	 *
	 * @return string self::OP_READ | self::OP_UPDATE | self::OP_DELETE
	 */
	public function get_operation_type(): string {
		$name = $this->get_name();

		if ( preg_match( '/(^|_)(delete|remove|reset)(_|$)/', $name ) ) {
			return self::OP_DELETE;
		}

		if ( preg_match( '/(^|_)(list|get|export|stats|count)(_|$)/', $name ) ) {
			return self::OP_READ;
		}

		return self::OP_UPDATE;
	}

	/**
	 * 檢查 MCP Settings 是否允許此操作類型
	 *
	 * 讀取永遠允許；OP_UPDATE / OP_DELETE 需站長在後台啟用對應開關。
	 * 取代舊版環境變數 ALLOW_UPDATE / ALLOW_DELETE 的設計（Issue #217）。
	 *
	 * @return bool
	 */
	final protected function is_operation_allowed(): bool {
		$op = $this->get_operation_type();

		if ( self::OP_READ === $op ) {
			return true;
		}

		$settings = new Settings();

		if ( self::OP_UPDATE === $op ) {
			return $settings->is_update_allowed();
		}

		if ( self::OP_DELETE === $op ) {
			return $settings->is_delete_allowed();
		}

		return false;
	}

	/**
	 * 取得完整的 ability 名稱（含前綴）
	 * 格式：power-course/{name}
	 *
	 * @return string
	 */
	final public function get_ability_name(): string {
		// Abilities API 名稱只允許 [a-z0-9-]，底線轉 dash
		return self::ABILITY_PREFIX . str_replace( '_', '-', $this->get_name() );
	}

	/**
	 * Permission callback — 強制使用 current_user_can() 檢查
	 * 此方法為 final，所有子類別統一走此檢查，不得繞過
	 *
	 * @return bool 當前用戶是否有足夠的 WordPress capability
	 */
	final public function permission_callback(): bool {
		// 未登入用戶永遠拒絕
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( $this->get_capability() );
	}

	/**
	 * 執行 tool 的統一入口
	 * 強制進行 permission check，通過後才呼叫 execute()
	 * 執行結果自動記錄至 ActivityLogger
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return mixed|\WP_Error 執行結果，或 permission 不足時回傳 WP_Error
	 */
	final public function run( array $args ): mixed {
		if ( ! $this->permission_callback() ) {
			return new \WP_Error(
				'mcp_permission_denied',
				sprintf(
					/* translators: 1: tool 名稱, 2: 所需 capability */
					__( 'Permission denied for MCP tool "%1$s". Required capability: %2$s', 'power-course' ),
					$this->get_name(),
					$this->get_capability()
				),
				[ 'status' => 403 ]
			);
		}

		if ( ! $this->is_operation_allowed() ) {
			$op           = $this->get_operation_type();
			$switch_label = self::OP_DELETE === $op ? __( 'Allow delete', 'power-course' ) : __( 'Allow update', 'power-course' );
			return new \WP_Error(
				'mcp_operation_not_allowed',
				sprintf(
					/* translators: 1: tool 名稱, 2: 操作類型 (update/delete), 3: 設定開關名稱 (Allow update / Allow delete) */
					__( 'Operation "%2$s" is disabled for MCP tool "%1$s". Please enable "%3$s" in WordPress Admin → Power Course → Settings → AI.', 'power-course' ),
					$this->get_name(),
					$op,
					$switch_label
				),
				[ 'status' => 403 ]
			);
		}

		return $this->execute( $args );
	}

	/**
	 * 向 WordPress Abilities API 註冊此 tool 的 ability
	 * 應在 wp_abilities_api_init hook 中呼叫
	 *
	 * @return void
	 */
	final public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			$this->get_ability_name(),
			[
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => $this->get_category(),
				'input_schema'        => $this->get_input_schema(),
				'execute_callback'    => function ( array $input ): mixed {
					return $this->run( $input );
				},
				'permission_callback' => function (): bool {
					return $this->permission_callback();
				},
				'meta'                => [
					'mcp' => [
						'public' => false,
					],
				],
			]
		);
	}
}
