<?php
/**
 * MCP Contact Remark Delete Tool
 *
 * 刪除一筆聯絡記錄（contact_remark）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\ContactRemark;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;

/**
 * Class ContactRemarkDeleteTool
 *
 * 對應 MCP ability：power-course/contact-remark-delete
 *
 * 安全守門：僅允許刪除 comment_type 為 contact_remark 的留言（防止誤刪一般文章留言），
 * 與 REST endpoint DELETE contact-remarks/{id} 的守門邏輯一致。
 */
final class ContactRemarkDeleteTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'contact_remark_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Delete contact remark', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Delete a contact remark by its ID. Only remarks of type contact_remark can be deleted.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The contact remark ID (comment ID) to delete.', 'power-course' ),
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted' => [
					'type'        => 'boolean',
					'description' => \__( 'Whether the contact remark was deleted successfully.', 'power-course' ),
				],
				'id'      => [
					'type'        => 'integer',
					'description' => \__( 'The deleted contact remark ID.', 'power-course' ),
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
	 * 執行刪除聯絡記錄
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{deleted: bool, id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$comment_id = (int) ( $args['id'] ?? 0 );

		// 輸入驗證：id 必填且為正整數。
		if ( $comment_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 資源存在檢查：聯絡記錄是否存在。
		$comment = \get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return new \WP_Error(
				'mcp_contact_remark_not_found',
				\__( 'The specified contact remark does not exist.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		// 安全守門：非 contact_remark 一律拒刪，避免誤刪一般文章留言。
		if ( 'contact_remark' !== $comment->comment_type ) {
			return new \WP_Error(
				'mcp_forbidden_comment_type',
				\__( 'Only contact remarks can be deleted.', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		// 第二參數 true：強制刪除（不進回收桶），與 REST endpoint 一致。
		$deleted = (bool) \wp_delete_comment( $comment_id, true );

		// wp_delete_comment 失敗時回傳 false，記錄失敗並回 500。
		if ( ! $deleted ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				'wp_delete_comment returned false',
				false
			);

			return new \WP_Error(
				'mcp_contact_remark_delete_failed',
				\__( 'Failed to delete the contact remark.', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'deleted' => $deleted,
			'id'      => $comment_id,
		];

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			true
		);

		return $result;
	}
}
