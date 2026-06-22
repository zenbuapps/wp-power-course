<?php
/**
 * MCP Email Send Schedule Tool
 *
 * 排程於指定時間寄送 Email 模板給指定使用者。
 * 複用 send-schedule 後端機制：Action Scheduler 單次排程（At::SEND_USERS_HOOK）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;

/**
 * Class EmailSendScheduleTool
 *
 * 對應 MCP ability：power-course/email_send_schedule
 */
final class EmailSendScheduleTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_send_schedule';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Schedule email send', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Schedule one or more email templates to be sent to the given users at a future time. The timestamp must be a future Unix timestamp (seconds, UTC).', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'email_ids' => [
					'type'        => 'array',
					'minItems'    => 1,
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'description' => \__( 'IDs of the email templates to send.', 'power-course' ),
				],
				'user_ids'  => [
					'type'        => 'array',
					'minItems'    => 1,
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'description' => \__( 'IDs of the recipient users.', 'power-course' ),
				],
				'timestamp' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The future send time as a Unix timestamp in seconds (UTC). Must be later than the current time.', 'power-course' ),
				],
			],
			'required'   => [ 'email_ids', 'user_ids', 'timestamp' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'action_id' => [
					'type'        => 'integer',
					'description' => \__( 'The Action Scheduler action ID for the scheduled send.', 'power-course' ),
				],
				'timestamp' => [
					'type'        => 'integer',
					'description' => \__( 'The scheduled send time (Unix timestamp, seconds).', 'power-course' ),
				],
				'email_ids' => [
					'type'        => 'array',
					'description' => \__( 'The scheduled email template IDs.', 'power-course' ),
					'items'       => [ 'type' => 'integer' ],
				],
				'user_ids'  => [
					'type'        => 'array',
					'description' => \__( 'The recipient user IDs.', 'power-course' ),
					'items'       => [ 'type' => 'integer' ],
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
	 * 執行排程寄送
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{action_id: int, timestamp: int, email_ids: array<int, int>, user_ids: array<int, int>}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		// 驗證時間戳記：必須為正整數且為未來時間
		$timestamp = isset( $args['timestamp'] ) ? (int) $args['timestamp'] : 0;
		if ( $timestamp <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'timestamp is required and must be a positive Unix timestamp in seconds.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( $timestamp <= time() ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'timestamp must be a future time. Use email_send_now to send immediately.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$validated = $this->validate_recipients_and_emails( $args );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		[ 'email_ids' => $email_ids, 'user_ids' => $user_ids ] = $validated;

		try {
			// 與 REST send-schedule 完全相同的後端機制：單次排程，由 At::SEND_USERS_HOOK 處理寄送
			$action_id = \as_schedule_single_action(
				$timestamp,
				At::SEND_USERS_HOOK,
				[
					'email_ids' => $email_ids,
					'user_ids'  => $user_ids,
				],
				At::AS_GROUP
			);
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);
			return new \WP_Error(
				'mcp_email_schedule_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'action_id' => (int) $action_id,
			'timestamp' => $timestamp,
			'email_ids' => $email_ids,
			'user_ids'  => $user_ids,
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

	/**
	 * 驗證收件人與 Email 模板，並回傳整理過的整數陣列。
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{email_ids: array<int, int>, user_ids: array<int, int>}|\WP_Error
	 */
	private function validate_recipients_and_emails( array $args ): array|\WP_Error {
		$raw_email_ids = $args['email_ids'] ?? [];
		$raw_user_ids  = $args['user_ids'] ?? [];

		if ( ! \is_array( $raw_email_ids ) || ! \is_array( $raw_user_ids ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'email_ids and user_ids must both be arrays.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$email_ids = array_values( array_filter( array_map( static fn( $id ) => \absint( (int) $id ), $raw_email_ids ) ) );
		$user_ids  = array_values( array_filter( array_map( static fn( $id ) => \absint( (int) $id ), $raw_user_ids ) ) );

		if ( empty( $email_ids ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'email_ids is required and must contain at least one valid template ID.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( empty( $user_ids ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_ids is required and must contain at least one valid user ID.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 驗證每個 email 模板存在且為 pe_email 類型
		foreach ( $email_ids as $email_id ) {
			$post = \get_post( $email_id );
			if ( ! $post instanceof \WP_Post || EmailCPT::POST_TYPE !== $post->post_type ) {
				return new \WP_Error(
					'mcp_email_not_found',
					\sprintf(
						/* translators: %d: Email 模板 ID */
						\__( 'Email template #%d was not found.', 'power-course' ),
						$email_id
					),
					[ 'status' => 404 ]
				);
			}
		}

		// 驗證每個收件人存在
		foreach ( $user_ids as $user_id ) {
			if ( ! \get_user_by( 'id', $user_id ) ) {
				return new \WP_Error(
					'mcp_user_not_found',
					\sprintf(
						/* translators: %d: 使用者 ID */
						\__( 'User #%d was not found.', 'power-course' ),
						$user_id
					),
					[ 'status' => 404 ]
				);
			}
		}

		return [
			'email_ids' => $email_ids,
			'user_ids'  => $user_ids,
		];
	}
}
