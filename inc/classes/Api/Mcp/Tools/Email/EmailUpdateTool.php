<?php
/**
 * MCP Email Update Tool
 *
 * 更新既有 Email 通知模板（pe_email CPT）的欄位。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;

/**
 * Class EmailUpdateTool
 *
 * 對應 MCP ability：power-course/email_update
 */
final class EmailUpdateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_update';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Update email template', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Update fields of an existing email template. Only the provided fields are changed; omitted fields stay untouched.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The email template ID to update.', 'power-course' ),
				],
				'name'              => [
					'type'        => 'string',
					'description' => \__( 'Template name (post title).', 'power-course' ),
				],
				'subject'           => [
					'type'        => 'string',
					'description' => \__( 'Email subject line.', 'power-course' ),
				],
				'description'       => [
					'type'        => 'string',
					'description' => \__( 'Email body in HTML.', 'power-course' ),
				],
				'short_description' => [
					'type'        => 'string',
					'description' => \__( 'Email body in JSON (easy-email editor format).', 'power-course' ),
				],
				'trigger_at'        => [
					'type'        => 'string',
					'enum'        => [ 'course_granted', 'course_finish', 'course_launch', 'chapter_enter', 'chapter_finish' ],
					'description' => \__( 'Trigger timing.', 'power-course' ),
				],
				'status'            => [
					'type'        => 'string',
					'enum'        => [ 'draft', 'publish' ],
					'description' => \__( 'Template status.', 'power-course' ),
				],
				'allow_repeat_send' => [
					'type'        => 'boolean',
					'description' => \__( 'Whether the same recipient may receive this email more than once.', 'power-course' ),
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
				'id'      => [
					'type'        => 'integer',
					'description' => \__( 'The updated email template ID.', 'power-course' ),
				],
				'updated' => [
					'type'        => 'boolean',
					'description' => \__( 'Whether the update succeeded.', 'power-course' ),
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
	 * 執行更新 Email 模板
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{id: int, updated: bool}|\WP_Error
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

		/** @var array<string, mixed> $postarr */
		$postarr               = [ 'ID' => $id ];
		/** @var array<string, mixed> $meta_input */
		$meta_input            = [];

		if ( isset( $args['name'] ) ) {
			$postarr['post_title'] = \sanitize_text_field( (string) $args['name'] );
		}

		if ( isset( $args['description'] ) ) {
			$postarr['post_content'] = \wp_kses_post( (string) $args['description'] );
		}

		// 使用 wp_slash 防止 JSON 跳脫字元在儲存時被過濾
		if ( isset( $args['short_description'] ) ) {
			$postarr['post_excerpt'] = \wp_slash( (string) $args['short_description'] );
		}

		if ( isset( $args['status'] ) ) {
			$status = \sanitize_key( (string) $args['status'] );
			if ( ! \in_array( $status, [ 'draft', 'publish' ], true ) ) {
				return new \WP_Error(
					'mcp_invalid_input',
					\__( 'status must be either "draft" or "publish".', 'power-course' ),
					[ 'status' => 422 ]
				);
			}
			$postarr['post_status'] = $status;
		}

		if ( isset( $args['trigger_at'] ) ) {
			$trigger_at = \sanitize_key( (string) $args['trigger_at'] );
			if ( ! \in_array( $trigger_at, AtHelper::$allowed_slugs, true ) ) {
				return new \WP_Error(
					'mcp_invalid_input',
					\sprintf(
						/* translators: 1: 傳入的觸發時機點, 2: 允許的觸發時機點清單 */
						\__( 'Invalid trigger_at "%1$s". Allowed values: %2$s', 'power-course' ),
						$trigger_at,
						\implode( ', ', AtHelper::$allowed_slugs )
					),
					[ 'status' => 422 ]
				);
			}
			$meta_input['trigger_at'] = $trigger_at;
		}

		if ( isset( $args['subject'] ) ) {
			$meta_input['subject'] = \sanitize_text_field( (string) $args['subject'] );
		}

		if ( isset( $args['allow_repeat_send'] ) ) {
			$meta_input['allow_repeat_send'] = \filter_var( $args['allow_repeat_send'], FILTER_VALIDATE_BOOLEAN ) ? 'yes' : 'no';
		}

		if ( ! empty( $meta_input ) ) {
			$postarr['meta_input'] = $meta_input;
		}

		// 僅有 ID 沒有其他可更新欄位時，視為無效輸入
		if ( [ 'ID' => $id ] === $postarr ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'No updatable fields were provided.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		/** @var array{ID: int, post_title?: string, post_content?: string, post_excerpt?: string, post_status?: string, meta_input?: array<string, mixed>} $postarr */
		$update_result = \wp_update_post( $postarr, true );

		if ( \is_wp_error( $update_result ) ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$update_result->get_error_message(),
				false
			);
			return new \WP_Error(
				'mcp_email_update_failed',
				$update_result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'id'      => $id,
			'updated' => true,
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
