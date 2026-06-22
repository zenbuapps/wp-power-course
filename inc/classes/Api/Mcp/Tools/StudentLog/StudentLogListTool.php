<?php
/**
 * MCP Student Log List Tool
 *
 * 跨學員 / 跨課程查詢學員活動日誌（wp_pc_student_logs）。
 * 與 Student 領域的 student_get_log 互補：本 tool 提供更廣的篩選維度
 * （chapter_id、log_type 白名單），且 user_id / course_id 皆為選填，
 * 因此可用於全站日誌稽核，而非僅限單一學員的單一課程。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\StudentLog;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;

/**
 * Class StudentLogListTool
 *
 * 對應 MCP ability：power-course/student-log-list
 */
final class StudentLogListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_log_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'List student activity logs', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Query student activity logs across students and courses (e.g. access granted, order created, chapter finished). All filters are optional, supporting pagination and filtering by user, course, chapter and log type.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'        => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Filter by student user ID. Optional; omit to query across all students.', 'power-course' ),
				],
				'course_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Filter by course ID. Optional; omit to query across all courses.', 'power-course' ),
				],
				'chapter_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Filter by chapter/lesson ID. Optional.', 'power-course' ),
				],
				'log_type'       => [
					'type'        => 'string',
					'enum'        => AtHelper::$allowed_slugs,
					'description' => \__( 'Filter by log type. Optional. Allowed values: course_granted, course_finish, course_launch, chapter_enter, chapter_finish, order_created, chapter_unfinished, course_removed, update_student.', 'power-course' ),
				],
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( 'Page number (starting from 1).', 'power-course' ),
				],
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => \__( 'Items per page (max 100).', 'power-course' ),
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
				'logs'         => [
					'type'        => 'array',
					'description' => \__( 'List of student activity logs.', 'power-course' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'         => [ 'type' => 'integer' ],
							'user_id'    => [ 'type' => 'integer' ],
							'course_id'  => [ 'type' => 'integer' ],
							'chapter_id' => [ 'type' => 'integer' ],
							'title'      => [ 'type' => 'string' ],
							'content'    => [ 'type' => 'string' ],
							'log_type'   => [ 'type' => 'string' ],
							'created_at' => [ 'type' => 'string' ],
						],
					],
				],
				'total'        => [
					'type'        => 'integer',
					'description' => \__( 'Total number of logs matching the filters.', 'power-course' ),
				],
				'total_pages'  => [
					'type'        => 'integer',
					'description' => \__( 'Total number of pages.', 'power-course' ),
				],
				'current_page' => [
					'type'        => 'integer',
					'description' => \__( 'Current page number.', 'power-course' ),
				],
				'page_size'    => [
					'type'        => 'integer',
					'description' => \__( 'Items per page.', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 *
	 * 沿用 Student 領域既有讀取型 tool（student_list / student_get / student_get_log
	 * 等）一致的 list_users capability，與單一學員日誌查詢權限對齊。
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
	 * 執行查詢日誌列表
	 *
	 * 複用 StudentLog\CRUD::get_list() 處理篩選、分頁與計數，避免自寫 raw SQL。
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{logs: array<int, array<string, mixed>>, total: int, total_pages: int, current_page: int, page_size: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$where = $this->build_where( $args );
		if ( \is_wp_error( $where ) ) {
			return $where;
		}

		// 對齊 StudentLog\CRUD::get_list() 宣告的 where 形狀（user_id / course_id 為選填過濾條件，get_where_sql 只處理實際存在的鍵）。
		/** @var array{paged: int, posts_per_page: int, user_id: int, course_id: int} $where_typed */
		$where_typed = $where;
		$result      = StudentLogCRUD::instance()->get_list( $where_typed );

		return [
			'logs'         => $this->format_logs( $result->list ),
			'total'        => (int) $result->total,
			'total_pages'  => (int) $result->total_pages,
			'current_page' => (int) $result->current_page,
			'page_size'    => (int) $result->page_size,
		];
	}

	/**
	 * 依輸入參數建立 CRUD::get_list() 所需的 where 條件陣列。
	 *
	 * - id 類欄位一律 absint，僅在 > 0 時加入
	 * - log_type 以 AtHelper::$allowed_slugs 白名單驗證，非法值回傳 WP_Error
	 * - 分頁參數 clamp 至合理範圍
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array<string, int|string>|\WP_Error
	 */
	private function build_where( array $args ): array|\WP_Error {
		$where = [
			'paged'          => isset( $args['paged'] ) ? max( 1, \absint( (int) $args['paged'] ) ) : 1,
			'posts_per_page' => isset( $args['posts_per_page'] )
				? min( 100, max( 1, \absint( (int) $args['posts_per_page'] ) ) )
				: 20,
		];

		foreach ( [ 'user_id', 'course_id', 'chapter_id' ] as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$value = \absint( (int) $args[ $key ] );
			if ( $value > 0 ) {
				$where[ $key ] = $value;
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
			$where['log_type'] = $log_type;
		}

		return $where;
	}

	/**
	 * 將 StudentLog 物件清單格式化為結構化陣列。
	 *
	 * @param array<int, \J7\PowerCourse\Resources\StudentLog\StudentLog> $list 日誌物件清單
	 * @return array<int, array<string, mixed>>
	 */
	private function format_logs( array $list ): array {
		$logs = [];
		foreach ( $list as $log ) {
			$logs[] = [
				'id'         => isset( $log->id ) ? (int) $log->id : 0,
				'user_id'    => isset( $log->user_id ) ? (int) $log->user_id : 0,
				'course_id'  => isset( $log->course_id ) ? (int) $log->course_id : 0,
				'chapter_id' => isset( $log->chapter_id ) ? (int) $log->chapter_id : 0,
				'title'      => isset( $log->title ) ? (string) $log->title : '',
				'content'    => isset( $log->content ) ? (string) $log->content : '',
				'log_type'   => isset( $log->log_type ) ? (string) $log->log_type : '',
				'created_at' => isset( $log->created_at ) ? (string) $log->created_at : '',
			];
		}
		return $logs;
	}
}
