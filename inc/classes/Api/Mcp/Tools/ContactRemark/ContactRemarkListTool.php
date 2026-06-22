<?php
/**
 * MCP Contact Remark List Tool
 *
 * 列出指定學員（被聯絡對象）的所有聯絡記錄（contact_remark）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\ContactRemark;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;

/**
 * Class ContactRemarkListTool
 *
 * 對應 MCP ability：power-course/contact-remark-list
 *
 * 聯絡記錄儲存於 wp_comments（comment_type=contact_remark、comment_post_ID=0），
 * 被聯絡對象的 user_id 存於 commentmeta commented_user_id。
 * 此 tool 複用與 REST endpoint GET contact-remarks 相同的查詢邏輯（get_comments）。
 */
final class ContactRemarkListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'contact_remark_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'List contact remarks', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'List all contact remarks (manual contact notes) recorded for a given student.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The student user ID whose contact remarks should be listed.', 'power-course' ),
				],
			],
			'required'   => [ 'user_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'        => [
					'type'        => 'integer',
					'description' => \__( 'The queried student user ID.', 'power-course' ),
				],
				'total'          => [
					'type'        => 'integer',
					'description' => \__( 'Total number of contact remarks.', 'power-course' ),
				],
				'contact_remarks' => [
					'type'        => 'array',
					'description' => \__( 'Contact remark records, ordered by date descending.', 'power-course' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'                => [
								'type'        => 'integer',
								'description' => \__( 'Contact remark ID (comment ID).', 'power-course' ),
							],
							'content'           => [
								'type'        => 'string',
								'description' => \__( 'The remark content.', 'power-course' ),
							],
							'date_created'      => [
								'type'        => 'string',
								'description' => \__( 'ISO-8601 creation date.', 'power-course' ),
							],
							'customer_note'     => [
								'type'        => 'boolean',
								'description' => \__( 'Whether this remark is also a customer-visible note.', 'power-course' ),
							],
							'added_by'          => [
								'type'        => 'string',
								'description' => \__( 'Display name of the author, or "system".', 'power-course' ),
							],
							'user_id'           => [
								'type'        => 'integer',
								'description' => \__( 'The author user ID (0 if system).', 'power-course' ),
							],
							'commented_user_id' => [
								'type'        => 'integer',
								'description' => \__( 'The student user ID this remark belongs to.', 'power-course' ),
							],
						],
					],
				],
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
		return 'contact_remark';
	}

	/**
	 * 執行列出聯絡記錄
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{user_id: int, total: int, contact_remarks: array<int, array{id: int, content: string, date_created: string, customer_note: bool, added_by: string, user_id: int, commented_user_id: int}>}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$user_id = (int) ( $args['user_id'] ?? 0 );

		// 輸入驗證：user_id 必填且為正整數。
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 資源存在檢查：學員是否存在。
		if ( ! \get_userdata( $user_id ) ) {
			return new \WP_Error(
				'mcp_user_not_found',
				\__( 'The specified student does not exist.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		try {
			// 複用 REST endpoint 相同查詢：wp_comments + commentmeta commented_user_id。
			$comments = \get_comments(
				[
					'type'       => 'contact_remark',
					'status'     => 'approve',
					'meta_key'   => 'commented_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (string) $user_id,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'orderby'    => 'comment_date_gmt',
					'order'      => 'DESC',
				]
			);

			$contact_remarks = [];
			if ( \is_array( $comments ) ) {
				foreach ( $comments as $comment ) {
					if ( ! $comment instanceof \WP_Comment ) {
						continue;
					}
					$contact_remarks[] = $this->shape_contact_remark( $comment );
				}
			}
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);

			return new \WP_Error(
				'mcp_contact_remark_list_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'user_id'         => $user_id,
			'total'           => \count( $contact_remarks ),
			'contact_remarks' => $contact_remarks,
		];

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'user_id' => $user_id,
				'total'   => $result['total'],
			],
			true
		);

		return $result;
	}

	/**
	 * 將 WP_Comment 塑形為聯絡記錄陣列
	 *
	 * 與 REST endpoint 的 shape_contact_remark 行為一致：added_by 有作者顯示作者名，否則為 'system'。
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return array{id: int, content: string, date_created: string, customer_note: bool, added_by: string, user_id: int, commented_user_id: int}
	 */
	private function shape_contact_remark( \WP_Comment $comment ): array {
		$author_id         = (int) $comment->user_id;
		$commented_user_id = (int) \get_comment_meta( (int) $comment->comment_ID, 'commented_user_id', true );
		$is_customer_note  = (bool) \get_comment_meta( (int) $comment->comment_ID, 'is_customer_note', true );

		// added_by：有作者顯示作者名，否則 'system'。
		$added_by = 'system';
		if ( $author_id > 0 ) {
			$author = \get_userdata( $author_id );
			if ( $author ) {
				$added_by = $author->display_name;
			}
		}

		return [
			'id'                => (int) $comment->comment_ID,
			'content'           => $comment->comment_content,
			'date_created'      => (string) \mysql2date( 'c', $comment->comment_date ),
			'customer_note'     => $is_customer_note,
			'added_by'          => $added_by,
			'user_id'           => $author_id,
			'commented_user_id' => $commented_user_id,
		];
	}
}
