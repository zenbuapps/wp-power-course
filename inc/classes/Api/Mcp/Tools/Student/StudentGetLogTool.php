<?php
/**
 * MCP Student Get Log Tool
 *
 * 查詢學員活動日誌（wp_pc_student_logs）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;

/**
 * Class StudentGetLogTool
 *
 * 對應 MCP ability：power-course/student_get_log
 */
final class StudentGetLogTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_get_log';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '查詢學員活動日誌', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '查詢學員活動日誌（例如權限開通、訂單建立、章節完成等事件），可依 user_id / course_id 篩選並分頁。', 'power-course' );
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
					'description' => \__( '學員 ID（optional）。', 'power-course' ),
				],
				'course_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '課程 ID（optional）。', 'power-course' ),
				],
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( '頁碼（從 1 開始）。', 'power-course' ),
				],
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => \__( '每頁筆數（最大 100）。', 'power-course' ),
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
					'description' => \__( '日誌清單。', 'power-course' ),
					'items'       => [ 'type' => 'object' ],
				],
				'total'        => [ 'type' => 'integer' ],
				'total_pages'  => [ 'type' => 'integer' ],
				'current_page' => [ 'type' => 'integer' ],
				'page_size'    => [ 'type' => 'integer' ],
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
		return 'student';
	}

	/**
	 * 執行查詢日誌
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{logs: array<int, array<string, mixed>>, total: int, total_pages: int, current_page: int, page_size: int}
	 */
	protected function execute( array $args ): array {
		$where = [
			'paged'          => isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1,
			'posts_per_page' => isset( $args['posts_per_page'] )
				? min( 100, max( 1, (int) $args['posts_per_page'] ) )
				: 20,
		];

		if ( isset( $args['user_id'] ) ) {
			$user_id = (int) $args['user_id'];
			if ( $user_id > 0 ) {
				$where['user_id'] = $user_id;
			}
		}

		if ( isset( $args['course_id'] ) ) {
			$course_id = (int) $args['course_id'];
			if ( $course_id > 0 ) {
				$where['course_id'] = $course_id;
			}
		}

		/** @var array{paged: int, posts_per_page: int, user_id: int, course_id: int} $where_typed */
		$where_typed = $where;

		$result = StudentLogCRUD::instance()->get_list( $where_typed );

		$logs = [];
		foreach ( $result->list as $log ) {
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

		return [
			'logs'         => $logs,
			'total'        => (int) $result->total,
			'total_pages'  => (int) $result->total_pages,
			'current_page' => (int) $result->current_page,
			'page_size'    => (int) $result->page_size,
		];
	}
}
