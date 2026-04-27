<?php
/**
 * MCP Tool：teacher_list — 列出講師
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Teacher\Service\Query;

/**
 * Class TeacherListTool
 * 列出所有講師（WP users with meta is_teacher = yes）
 */
final class TeacherListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'teacher_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '列出講師', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__(
			'列出 Power Course 系統中所有講師（WP users with meta `is_teacher = yes`），支援分頁、搜尋、排序。適用於查詢講師總覽或在管理介面選取講師。',
			'power-course'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'paged'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( '頁碼（從 1 開始）', 'power-course' ),
				],
				'number'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => \__( '每頁筆數，最大 100', 'power-course' ),
				],
				'search'  => [
					'type'        => 'string',
					'description' => \__( '搜尋關鍵字（比對 user_login / user_email / display_name）', 'power-course' ),
				],
				'orderby' => [
					'type'        => 'string',
					'enum'        => [ 'ID', 'display_name', 'user_login', 'user_email', 'registered' ],
					'default'     => 'ID',
					'description' => \__( '排序欄位', 'power-course' ),
				],
				'order'   => [
					'type'        => 'string',
					'enum'        => [ 'ASC', 'DESC' ],
					'default'     => 'DESC',
					'description' => \__( '排序方向', 'power-course' ),
				],
			],
			'required'   => [],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items'       => [
					'type'        => 'array',
					'description' => \__( '講師清單', 'power-course' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'total'       => [
					'type'        => 'integer',
					'description' => \__( '總筆數', 'power-course' ),
				],
				'total_pages' => [
					'type'        => 'integer',
					'description' => \__( '總頁數', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'list_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'teacher';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	protected function execute( array $args ): array {
		return Query::list( $args );
	}
}
