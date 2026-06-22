<?php
/**
 * MCP Announcement Update Tool
 *
 * 更新課程公告，複用 Announcement Crud Service 的更新邏輯。未提供的欄位不會被覆寫。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Announcement;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Announcement\Service\Crud;
use J7\PowerCourse\Resources\Announcement\Service\Query;
use J7\PowerCourse\Resources\Announcement\Utils\Utils;

/**
 * Class AnnouncementUpdateTool
 *
 * 對應 MCP ability：power-course/announcement_update
 */
final class AnnouncementUpdateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'announcement_update';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Update announcement', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Update a course announcement. Only the fields you provide are changed; omitted fields are left untouched.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'               => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The announcement ID to update.', 'power-course' ),
				],
				'post_title'       => [
					'type'        => 'string',
					'description' => \__( 'The announcement title.', 'power-course' ),
				],
				'post_content'     => [
					'type'        => 'string',
					'description' => \__( 'The announcement content. Allows the same HTML as a WordPress post.', 'power-course' ),
				],
				'post_status'      => [
					'type'        => 'string',
					'enum'        => [ 'publish', 'future', 'draft' ],
					'description' => \__( 'The post status. Use future together with a future post_date to schedule.', 'power-course' ),
				],
				'post_date'        => [
					'type'        => 'string',
					'description' => \__( 'The publish date (Y-m-d H:i:s, site timezone).', 'power-course' ),
				],
				'parent_course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The course ID this announcement belongs to. Must be a valid course product.', 'power-course' ),
				],
				'visibility'       => [
					'type'        => 'string',
					'enum'        => [ Utils::VISIBILITY_PUBLIC, Utils::VISIBILITY_ENROLLED ],
					'description' => \__( 'Visibility: public (everyone) or enrolled (purchasers only).', 'power-course' ),
				],
				'end_at'           => [
					'type'        => [ 'integer', 'string' ],
					'description' => \__( 'Expiration time as a 10-digit Unix timestamp, must be later than post_date. Pass an empty string to clear it.', 'power-course' ),
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
				'id' => [
					'type'        => 'integer',
					'description' => \__( 'The ID of the updated announcement.', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'announcement';
	}

	/**
	 * 執行更新公告
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		// 1. 驗證 id。
		$id = \absint( (int) ( $args['id'] ?? 0 ) );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 2. 資源存在檢查（提早回 404，不依賴 Crud 內的中文例外訊息）。
		if ( null === Query::get( $id ) ) {
			return new \WP_Error(
				'mcp_announcement_not_found',
				\__( 'Announcement does not exist', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		// 3. 只收集呼叫者實際提供的欄位（未提供者不覆寫）。
		$data = [];
		if ( array_key_exists( 'post_title', $args ) ) {
			$data['post_title'] = \sanitize_text_field( (string) $args['post_title'] );
		}
		if ( array_key_exists( 'post_content', $args ) ) {
			$data['post_content'] = \wp_kses_post( (string) $args['post_content'] );
		}
		if ( array_key_exists( 'post_status', $args ) ) {
			$data['post_status'] = \sanitize_key( (string) $args['post_status'] );
		}
		if ( array_key_exists( 'post_date', $args ) && '' !== $args['post_date'] ) {
			$data['post_date'] = \sanitize_text_field( (string) $args['post_date'] );
		}
		if ( array_key_exists( 'parent_course_id', $args ) ) {
			$data['parent_course_id'] = \absint( (int) $args['parent_course_id'] );
		}
		if ( array_key_exists( 'visibility', $args ) ) {
			$data['visibility'] = \sanitize_key( (string) $args['visibility'] );
		}
		if ( array_key_exists( 'end_at', $args ) ) {
			$data['end_at'] = is_numeric( $args['end_at'] ) ? (int) $args['end_at'] : '';
		}

		if ( empty( $data ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'No updatable fields were provided.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 4. 複用既有 Crud::update 業務邏輯。
		try {
			$updated_id = Crud::update( $id, $data );
		} catch ( \RuntimeException $e ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$e->getMessage(),
				false
			);
			return $this->runtime_exception_to_wp_error( $e );
		}

		$result = [ 'id' => $updated_id ];

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
	 * 將 Crud::update 拋出的 RuntimeException 轉為對應 HTTP 狀態碼的 WP_Error。
	 *
	 * 公告不存在以 404 回傳；更新失敗以 500 回傳；其餘參數驗證類錯誤以 422 回傳。
	 *
	 * @param \RuntimeException $e 例外物件。
	 * @return \WP_Error
	 */
	private function runtime_exception_to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( '公告不存在' === $message ) {
			return new \WP_Error( 'mcp_announcement_not_found', $message, [ 'status' => 404 ] );
		}

		if ( \str_contains( $message, '更新公告失敗' ) ) {
			return new \WP_Error( 'mcp_announcement_update_failed', $message, [ 'status' => 500 ] );
		}

		return new \WP_Error( 'mcp_invalid_input', $message, [ 'status' => 422 ] );
	}
}
