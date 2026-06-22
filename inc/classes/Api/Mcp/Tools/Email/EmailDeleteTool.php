<?php
/**
 * MCP Email Delete Tool
 *
 * 刪除（移至回收桶）Email 通知模板。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;

/**
 * Class EmailDeleteTool
 *
 * 對應 MCP ability：power-course/email_delete
 */
final class EmailDeleteTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Delete email template', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Delete an email template by moving it to trash (consistent with the admin UI behaviour).', 'power-course' );
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
					'description' => \__( 'The email template ID to delete.', 'power-course' ),
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
					'description' => \__( 'Whether the template was moved to trash successfully.', 'power-course' ),
				],
				'id'      => [
					'type'        => 'integer',
					'description' => \__( 'The deleted email template ID.', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'manage_options';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'email';
	}

	/**
	 * 執行刪除 Email 模板
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{deleted: bool, id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || EmailCPT::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'mcp_email_not_found',
				\__( 'The specified email template was not found.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$trashed = \wp_trash_post( $id );
		if ( ! $trashed ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				'wp_trash_post returned false',
				false
			);
			return new \WP_Error(
				'mcp_email_delete_failed',
				\__( 'Failed to delete the email template.', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'deleted' => true,
			'id'      => $id,
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
