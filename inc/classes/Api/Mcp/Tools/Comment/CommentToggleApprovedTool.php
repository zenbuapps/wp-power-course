<?php
/**
 * MCP Tool — comment_toggle_approved
 * 切換留言審核狀態（需 moderate_comments）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Comment\Service\Crud as CommentCrud;

/**
 * Class CommentToggleApprovedTool
 * 切換留言審核狀態的 MCP tool，包裝 CommentCrud::toggle_approved()
 */
final class CommentToggleApprovedTool extends AbstractTool {

	/** 所屬 category */
	private const CATEGORY = 'comment';

	/**
	 * 活動記錄器
	 *
	 * @var ActivityLogger
	 */
	private ActivityLogger $activity_logger;

	/**
	 * 建構子
	 *
	 * @param ActivityLogger|null $activity_logger 活動記錄器
	 */
	public function __construct( ?ActivityLogger $activity_logger = null ) {
		$this->activity_logger = $activity_logger ?? new ActivityLogger();
	}

	/**
	 * 取得 tool 名稱
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'comment_toggle_approved';
	}

	/**
	 * 取得人類可讀標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return \__( '切換留言審核狀態', 'power-course' );
	}

	/**
	 * 取得描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return \__( '切換留言審核狀態（approved ↔ unapproved），連同子留言一併切換。垃圾留言無法切換。', 'power-course' );
	}

	/**
	 * 取得輸入 JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'comment_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => '要切換狀態的留言 ID',
				],
			],
			'required'   => [ 'comment_id' ],
		];
	}

	/**
	 * 取得輸出 JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'code'    => [
					'type'        => 'integer',
					'description' => 'HTTP 狀態碼',
				],
				'message' => [
					'type'        => 'string',
					'description' => '結果訊息',
				],
				'data'    => [
					'type'        => 'object',
					'description' => '結果資料（成功時包含 ids 與 approved）',
				],
			],
		];
	}

	/**
	 * 取得所需 WordPress capability
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'moderate_comments';
	}

	/**
	 * 取得所屬 category
	 *
	 * @return string
	 */
	public function get_category(): string {
		return self::CATEGORY;
	}

	/**
	 * 執行 tool
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{code: int, message: string, data: array<string, mixed>|null}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$start_at = microtime( true );

		$comment_id = isset( $args['comment_id'] ) ? (int) $args['comment_id'] : 0;
		if ( $comment_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( '缺少必要參數 comment_id 或其值不合法', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$result = CommentCrud::toggle_approved( $comment_id );

		$duration_ms = (int) ( ( microtime( true ) - $start_at ) * 1000 );
		$this->activity_logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			200 === $result['code'],
			null,
			$duration_ms
		);

		return $result;
	}
}
