<?php
/**
 * MCP Email Create Tool
 *
 * 建立 Email 通知模板（pe_email CPT）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;

/**
 * Class EmailCreateTool
 *
 * 對應 MCP ability：power-course/email_create
 */
final class EmailCreateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_create';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Create email template', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Create a course notification email template. You may set the name, subject, HTML body and the trigger timing (when the email is automatically sent).', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'              => [
					'type'        => 'string',
					'description' => \__( 'Template name (post title).', 'power-course' ),
				],
				'subject'           => [
					'type'        => 'string',
					'description' => \__( 'Email subject line. Supports replacement tags such as {{user.display_name}}.', 'power-course' ),
				],
				'description'       => [
					'type'        => 'string',
					'description' => \__( 'Email body in HTML.', 'power-course' ),
				],
				'short_description' => [
					'type'        => 'string',
					'description' => \__( 'Email body in JSON (easy-email editor format). Optional.', 'power-course' ),
				],
				'trigger_at'        => [
					'type'        => 'string',
					'enum'        => [ 'course_granted', 'course_finish', 'course_launch', 'chapter_enter', 'chapter_finish' ],
					'default'     => 'course_granted',
					'description' => \__( 'Trigger timing: course_granted, course_finish, course_launch, chapter_enter or chapter_finish.', 'power-course' ),
				],
				'status'            => [
					'type'        => 'string',
					'enum'        => [ 'draft', 'publish' ],
					'default'     => 'draft',
					'description' => \__( 'Template status. Only published templates are triggered automatically.', 'power-course' ),
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
				'id' => [
					'type'        => 'integer',
					'description' => \__( 'The newly created email template ID.', 'power-course' ),
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
	 * 執行建立 Email 模板
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		// 觸發時機點：驗證在允許清單內，預設 course_granted
		$trigger_at = isset( $args['trigger_at'] ) ? \sanitize_key( (string) $args['trigger_at'] ) : AtHelper::COURSE_GRANTED;
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

		$status = isset( $args['status'] ) ? \sanitize_key( (string) $args['status'] ) : 'draft';
		if ( ! \in_array( $status, [ 'draft', 'publish' ], true ) ) {
			$status = 'draft';
		}

		$name = isset( $args['name'] ) && '' !== (string) $args['name']
		? \sanitize_text_field( (string) $args['name'] )
		: \__( 'New Email', 'power-course' );

		$postarr = [
			'post_type'    => EmailCPT::POST_TYPE,
			'post_title'   => $name,
			'post_content' => isset( $args['description'] ) ? \wp_kses_post( (string) $args['description'] ) : '',
			'post_status'  => $status,
			'post_author'  => \get_current_user_id(),
			'meta_input'   => [
				'trigger_at' => $trigger_at,
			],
		];

		if ( isset( $args['subject'] ) ) {
			$postarr['meta_input']['subject'] = \sanitize_text_field( (string) $args['subject'] );
		}

		// 使用 wp_slash 防止 JSON 跳脫字元在儲存時被過濾
		if ( isset( $args['short_description'] ) ) {
			$postarr['post_excerpt'] = \wp_slash( (string) $args['short_description'] );
		}

		$post_id = \wp_insert_post( $postarr, true );

		if ( \is_wp_error( $post_id ) ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$post_id->get_error_message(),
				false
			);
			return new \WP_Error(
				'mcp_email_create_failed',
				$post_id->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$result = [ 'id' => (int) $post_id ];

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
