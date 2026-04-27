<?php
/**
 * MCP Student List Tool
 *
 * 列出學員（可依課程 ID / 到期狀態篩選）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Student\Service\Query;

/**
 * Class StudentListTool
 *
 * 對應 MCP ability：power-course/student_list
 */
final class StudentListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '列出學員', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '列出學員清單，可依課程 ID 篩選、關鍵字搜尋，並支援分頁。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'course_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '課程 ID；用於列出該課程的所有學員（對應 avl_course_ids meta）。', 'power-course' ),
				],
				'search'         => [
					'type'        => 'string',
					'description' => \__( '關鍵字搜尋（帳號 / Email / 顯示名稱）。', 'power-course' ),
				],
				'search_field'   => [
					'type'        => 'string',
					'enum'        => [ 'default', 'email', 'name', 'id' ],
					'default'     => 'default',
					'description' => \__( '搜尋欄位。', 'power-course' ),
				],
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => \__( '每頁筆數（最大 100）。', 'power-course' ),
				],
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( '頁碼（從 1 開始）。', 'power-course' ),
				],
			],
			'required'   => [ 'course_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'students'    => [
					'type'        => 'array',
					'description' => \__( '學員資料陣列。', 'power-course' ),
					'items'       => [ 'type' => 'object' ],
				],
				'total'       => [
					'type'        => 'integer',
					'description' => \__( '本次回傳的學員數量。', 'power-course' ),
				],
				'total_pages' => [
					'type'        => 'integer',
					'description' => \__( '總頁數。', 'power-course' ),
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
		return 'student';
	}

	/**
	 * 執行列出學員
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{students: array<int, array<string, mixed>>, total: int, total_pages: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['course_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$course_id = (int) $args['course_id'];
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id 需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$query_args = [
			'meta_key'       => 'avl_course_ids',
			'meta_value'     => (string) $course_id,
			'posts_per_page' => isset( $args['posts_per_page'] )
				? min( 100, max( 1, (int) $args['posts_per_page'] ) )
				: 20,
			'paged'          => isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1,
		];

		if ( isset( $args['search'] ) && \is_string( $args['search'] ) && '' !== $args['search'] ) {
			$query_args['search'] = \sanitize_text_field( $args['search'] );
		}
		if ( isset( $args['search_field'] ) && \is_string( $args['search_field'] ) ) {
			$query_args['search_field'] = \sanitize_key( $args['search_field'] );
		}

		try {
			$query      = new Query( $query_args );
			$users      = $query->get_users();
			$pagination = $query->get_pagination();
		} catch ( \Throwable $th ) {
			return new \WP_Error(
				'mcp_student_list_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$students = [];
		foreach ( $users as $user ) {
			$students[] = [
				'user_id'         => (int) $user->ID,
				'user_login'      => (string) $user->user_login,
				'user_email'      => (string) $user->user_email,
				'display_name'    => (string) $user->display_name,
				'user_registered' => (string) $user->user_registered,
			];
		}

		return [
			'students'    => $students,
			'total'       => (int) $pagination->total,
			'total_pages' => (int) $pagination->total_pages,
		];
	}
}
