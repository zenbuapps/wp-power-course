<?php
/**
 * MCP Student Log Count Tool
 *
 * 統計符合條件的學員活動日誌筆數（wp_pc_student_logs）。
 * 為 student_log_list 的輕量版：只回傳總筆數與套用的篩選條件，
 * 適合 AI 在抓取明細前先確認資料量。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\StudentLog;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;

/**
 * Class StudentLogCountTool
 *
 * 對應 MCP ability：power-course/student-log-count
 */
final class StudentLogCountTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_log_count';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Count student activity logs', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Count student activity logs matching the given filters (user, course, chapter, log type). Returns only the total count, useful before fetching detailed records.', 'power-course' );
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
					'description' => \__( 'Filter by student user ID. Optional.', 'power-course' ),
				],
				'course_id'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Filter by course ID. Optional.', 'power-course' ),
				],
				'chapter_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Filter by chapter/lesson ID. Optional.', 'power-course' ),
				],
				'log_type'   => [
					'type'        => 'string',
					'enum'        => AtHelper::$allowed_slugs,
					'description' => \__( 'Filter by log type. Optional. Allowed values: course_granted, course_finish, course_launch, chapter_enter, chapter_finish, order_created, chapter_unfinished, course_removed, update_student.', 'power-course' ),
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
				'total'   => [
					'type'        => 'integer',
					'description' => \__( 'Total number of logs matching the filters.', 'power-course' ),
				],
				'filters' => [
					'type'        => 'object',
					'description' => \__( 'The filters that were applied.', 'power-course' ),
					'properties'  => [
						'user_id'    => [ 'type' => 'integer' ],
						'course_id'  => [ 'type' => 'integer' ],
						'chapter_id' => [ 'type' => 'integer' ],
						'log_type'   => [ 'type' => 'string' ],
					],
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 *
	 * 與 student_log_list / student_get_log 一致使用 list_users capability。
	 */
	public function get_capability(): string {
		return 'list_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'student_log';
	}

	/**
	 * 執行統計日誌筆數
	 *
	 * 複用 StudentLog\CRUD::get_list() 內建的 COUNT(*) 計數，只取總筆數，
	 * posts_per_page 固定為 1 以最小化明細查詢成本。
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{total: int, filters: array<string, int|string>}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$filters = [];

		foreach ( [ 'user_id', 'course_id', 'chapter_id' ] as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$value = \absint( (int) $args[ $key ] );
			if ( $value > 0 ) {
				$filters[ $key ] = $value;
			}
		}

		if ( isset( $args['log_type'] ) && '' !== $args['log_type'] ) {
			$log_type = \sanitize_text_field( (string) $args['log_type'] );
			if ( ! \in_array( $log_type, AtHelper::$allowed_slugs, true ) ) {
				return new \WP_Error(
					'mcp_invalid_input',
					sprintf(
						/* translators: %s: 使用者傳入的非法 log_type 值 */
						\__( 'Invalid log_type "%s". Please use one of the allowed log types.', 'power-course' ),
						$log_type
					),
					[ 'status' => 422 ]
				);
			}
			$filters['log_type'] = $log_type;
		}

		$where = array_merge(
			$filters,
			[
				'paged'          => 1,
				'posts_per_page' => 1,
			]
		);

		// 對齊 StudentLog\CRUD::get_list() 宣告的 where 形狀（user_id / course_id 為選填過濾條件，get_where_sql 只處理實際存在的鍵）。
		/** @var array{paged: int, posts_per_page: int, user_id: int, course_id: int} $where_typed */
		$where_typed = $where;
		$result      = StudentLogCRUD::instance()->get_list( $where_typed );

		return [
			'total'   => (int) $result->total,
			'filters' => $filters,
		];
	}
}
