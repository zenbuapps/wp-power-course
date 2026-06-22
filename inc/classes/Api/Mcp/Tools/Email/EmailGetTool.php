<?php
/**
 * MCP Email Get Tool
 *
 * 取得單一 Email 通知模板的完整詳情（含主旨、內容、觸發條件）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;

/**
 * Class EmailGetTool
 *
 * 對應 MCP ability：power-course/email_get
 */
final class EmailGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Get email template', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Get the full detail of a single email template, including subject, HTML body, JSON body and trigger condition.', 'power-course' );
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
					'description' => \__( 'The email template ID.', 'power-course' ),
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
				'id'                => [ 'type' => 'string' ],
				'name'              => [ 'type' => 'string' ],
				'status'            => [ 'type' => 'string' ],
				'subject'           => [ 'type' => 'string' ],
				'description'       => [
					'type'        => 'string',
					'description' => \__( 'Email body in HTML (MJML rendered).', 'power-course' ),
				],
				'short_description' => [
					'type'        => 'string',
					'description' => \__( 'Email body in JSON (easy-email editor format).', 'power-course' ),
				],
				'trigger_at'        => [ 'type' => 'string' ],
				'allow_repeat_send' => [ 'type' => 'boolean' ],
				'condition'         => [
					'type'        => 'object',
					'description' => \__( 'Trigger condition settings (may be null).', 'power-course' ),
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
	 * 執行取得 Email 模板
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return EmailResource|\WP_Error
	 */
	protected function execute( array $args ): EmailResource|\WP_Error {
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

		// 以 API 格式回傳：condition 為純陣列，方便序列化
		return new EmailResource( $id, true, true );
	}
}
