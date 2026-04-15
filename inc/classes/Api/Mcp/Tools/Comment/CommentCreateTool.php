<?php
/**
 * MCP Tool — comment_create
 * 發表章節/課程留言（預設以目前登入者身份；代他人發言需 moderate_comments）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Comment\Service\Crud as CommentCrud;

/**
 * Class CommentCreateTool
 * 發表留言的 MCP tool，包裝 CommentCrud::create()
 */
final class CommentCreateTool extends AbstractTool {

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
		return 'comment_create';
	}

	/**
	 * 取得人類可讀標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return \__( '發表留言', 'power-course' );
	}

	/**
	 * 取得描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return \__( '發表留言或評價。預設以目前登入者身份發言；若指定他人 user_id，需具 moderate_comments 能力。', 'power-course' );
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
				'chapter_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => '章節或課程 post ID（同 comment_post_ID）',
				],
				'comment_post_ID' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => '留言所屬 post ID（與 chapter_id 擇一即可）',
				],
				'content'         => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => '留言內容（與 comment_content 擇一即可）',
				],
				'comment_content' => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => '留言內容',
				],
				'comment_type'    => [
					'type'        => 'string',
					'enum'        => [ 'comment', 'review' ],
					'default'     => 'comment',
					'description' => '留言類型',
				],
				'user_id'         => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => '代發者 user_id（選填，不等於目前登入者時需 moderate_comments）',
				],
				'comment_parent'  => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => '父留言 ID（回覆用）',
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
				'code'    => [
					'type'        => 'integer',
					'description' => 'HTTP 狀態碼（200 成功 / 400 失敗）',
				],
				'message' => [
					'type'        => 'string',
					'description' => '結果訊息',
				],
				'data'    => [
					'type'        => 'object',
					'description' => '結果資料（成功時包含 id）',
				],
			],
		];
	}

	/**
	 * 取得所需 WordPress capability
	 *
	 * 基本 capability 為 read（即登入者）；代他人發言需進一步檢查 moderate_comments（於 execute 時進行）。
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'read';
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

		$current_user_id = \get_current_user_id();

		// chapter_id 與 comment_post_ID 擇一
		if ( isset( $args['chapter_id'] ) && ! isset( $args['comment_post_ID'] ) ) {
			$args['comment_post_ID'] = (int) $args['chapter_id'];
		}

		// content 與 comment_content 擇一
		if ( isset( $args['content'] ) && ! isset( $args['comment_content'] ) ) {
			$args['comment_content'] = (string) $args['content'];
		}

		// 必要欄位檢查
		if ( empty( $args['comment_post_ID'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( '缺少必要參數 chapter_id 或 comment_post_ID', 'power-course' ),
				[ 'status' => 422 ]
			);
		}
		if ( empty( $args['comment_content'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( '缺少必要參數 content 或 comment_content', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 代他人發言權限檢查
		$target_user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : $current_user_id;
		if ( $target_user_id !== $current_user_id && ! \current_user_can( 'moderate_comments' ) ) {
			return new \WP_Error(
				'mcp_permission_denied',
				\__( '代他人發表留言需要 moderate_comments 能力', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		// 預設 comment_type 為 comment
		if ( ! isset( $args['comment_type'] ) || '' === $args['comment_type'] ) {
			$args['comment_type'] = 'comment';
		}

		$result = CommentCrud::create( $args, $target_user_id );

		$duration_ms = (int) ( ( microtime( true ) - $start_at ) * 1000 );
		$this->activity_logger->log(
			$this->get_name(),
			$current_user_id,
			$args,
			$result,
			200 === $result['code'],
			null,
			$duration_ms
		);

		return $result;
	}
}
