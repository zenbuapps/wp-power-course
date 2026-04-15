<?php
/**
 * MCP Tool — comment_list
 * 列出章節/課程留言（需 moderate_comments 能力）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Api\Comment as CommentApi;
use J7\PowerCourse\Resources\Comment\Service\Query as CommentQuery;

/**
 * Class CommentListTool
 * 列出留言的 MCP tool，包裝 CommentQuery::list()
 */
final class CommentListTool extends AbstractTool {

	/** 所屬 category，對應 Settings 啟用判斷 */
	private const CATEGORY = 'comment';

	/**
	 * 活動記錄器（依賴注入，測試可替換）
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
		return 'comment_list';
	}

	/**
	 * 取得人類可讀標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return \__( '列出留言', 'power-course' );
	}

	/**
	 * 取得描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return \__( '列出指定 post_id 的留言，支援分頁、類型與狀態篩選。需要 moderate_comments 能力。', 'power-course' );
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
				'post_id'      => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => '所屬 post (課程/章節) ID，0 表示不限',
				],
				'type'         => [
					'type'        => 'string',
					'enum'        => [ 'comment', 'review' ],
					'default'     => 'review',
					'description' => '留言類型：comment 或 review',
				],
				'status'       => [
					'type'        => 'string',
					'enum'        => [ 'approve', 'hold', 'spam', 'trash', 'all' ],
					'default'     => 'all',
					'description' => '留言狀態篩選',
				],
				'user_id'      => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => '限定用戶 ID（可選）',
				],
				'paged'        => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => '頁數',
				],
				'number'       => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => '每頁筆數（1 ~ 100）',
				],
				'hierarchical' => [
					'type'        => 'string',
					'enum'        => [ 'threaded', 'flat', 'false' ],
					'default'     => 'threaded',
					'description' => '階層模式',
				],
			],
			'required'   => [],
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
				'comments'     => [
					'type'        => 'array',
					'description' => '留言列表',
				],
				'total'        => [
					'type'        => 'integer',
					'description' => '符合條件的留言總數',
				],
				'total_pages'  => [
					'type'        => 'integer',
					'description' => '總頁數',
				],
				'current_page' => [
					'type'        => 'integer',
					'description' => '目前頁數',
				],
				'page_size'    => [
					'type'        => 'integer',
					'description' => '每頁筆數',
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
	 * @return array{comments: array<int, array<string, mixed>>, total: int, total_pages: int, current_page: int, page_size: int}
	 */
	protected function execute( array $args ): array {
		$start_at = microtime( true );

		// 透過 CommentApi singleton 取得 formatter，保持格式一致
		$api       = CommentApi::instance();
		$formatter = fn( \WP_Comment $comment, int $depth, array $sub_args ): array => $api->format_comment_details( $comment, $depth, $sub_args );

		$result = CommentQuery::list( $args, $formatter );

		// 讀取操作也記錄（Phase 2 規範：寫入必寫；此處 list 是 moderate 權限敏感操作，同時記錄有利稽核）
		$duration_ms = (int) ( ( microtime( true ) - $start_at ) * 1000 );
		$this->activity_logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'total' => $result['total'],
				'count' => count( $result['comments'] ),
			],
			true,
			null,
			$duration_ms
		);

		return $result;
	}
}
