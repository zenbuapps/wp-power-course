<?php
/**
 * MCP Contact Remark Create Tool
 *
 * 對指定學員新增一筆聯絡記錄（contact_remark）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\ContactRemark;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;

/**
 * Class ContactRemarkCreateTool
 *
 * 對應 MCP ability：power-course/contact-remark-create
 *
 * 聯絡記錄儲存於 wp_comments（comment_type=contact_remark、comment_post_ID=0），
 * 被聯絡對象的 user_id 存於 commentmeta commented_user_id，
 * is_customer_note 旗標存於 commentmeta is_customer_note。
 * 此 tool 複用與 REST endpoint POST contact-remarks 相同的寫入邏輯。
 */
final class ContactRemarkCreateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'contact_remark_create';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Create contact remark', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Add a contact remark (manual contact note) to a given student. The current user is recorded as the author.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The student user ID the contact remark belongs to.', 'power-course' ),
				],
				'note'             => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => \__( 'The contact remark content. Cannot be empty.', 'power-course' ),
				],
				'is_customer_note' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => \__( 'Whether this remark is also a customer-visible note.', 'power-course' ),
				],
			],
			'required'   => [ 'user_id', 'note' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'               => [
					'type'        => 'integer',
					'description' => \__( 'The created contact remark ID (comment ID).', 'power-course' ),
				],
				'user_id'          => [
					'type'        => 'integer',
					'description' => \__( 'The student user ID this remark belongs to.', 'power-course' ),
				],
				'is_customer_note' => [
					'type'        => 'boolean',
					'description' => \__( 'Whether this remark is a customer-visible note.', 'power-course' ),
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
	 * 執行新增聯絡記錄
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{id: int, user_id: int, is_customer_note: bool}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$user_id          = (int) ( $args['user_id'] ?? 0 );
		$note             = isset( $args['note'] ) ? \trim( \sanitize_textarea_field( (string) $args['note'] ) ) : '';
		$is_customer_note = ! empty( $args['is_customer_note'] );

		// 輸入驗證：user_id 必填且為正整數。
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 輸入驗證：note 不可為空。
		if ( '' === $note ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'note content cannot be empty.', 'power-course' ),
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

		$current_user_id = \get_current_user_id();
		$current_user    = $current_user_id ? \get_userdata( $current_user_id ) : false;

		// 複用 REST endpoint 相同寫入：wp_insert_comment + 兩個 commentmeta。
		$comment_id = \wp_insert_comment(
			[
				'comment_post_ID'      => 0,
				'comment_type'         => 'contact_remark',
				'comment_content'      => $note,
				'user_id'              => $current_user_id,
				'comment_author'       => $current_user ? $current_user->display_name : '',
				'comment_author_email' => $current_user ? $current_user->user_email : '',
				'comment_approved'     => 1,
			]
		);

		// wp_insert_comment 失敗時回傳 falsy（false / 0），記錄失敗並回 500。
		if ( ! $comment_id ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				'wp_insert_comment returned a falsy value',
				false
			);

			return new \WP_Error(
				'mcp_contact_remark_create_failed',
				\__( 'Failed to create the contact remark.', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		\add_comment_meta( (int) $comment_id, 'commented_user_id', $user_id );
		\add_comment_meta( (int) $comment_id, 'is_customer_note', $is_customer_note ? '1' : '' );

		$result = [
			'id'               => (int) $comment_id,
			'user_id'          => $user_id,
			'is_customer_note' => $is_customer_note,
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
