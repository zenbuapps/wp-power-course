<?php
/**
 * MCP Announcement Create Tool
 *
 * 建立課程公告，複用 Announcement Crud Service 的建立邏輯。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Announcement;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Announcement\Service\Crud;
use J7\PowerCourse\Resources\Announcement\Utils\Utils;

/**
 * Class AnnouncementCreateTool
 *
 * 對應 MCP ability：power-course/announcement_create
 */
final class AnnouncementCreateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'announcement_create';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Create announcement', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Create a course announcement. The announcement is attached to a course and supports scheduling and visibility control.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_title'       => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => \__( 'The announcement title.', 'power-course' ),
				],
				'parent_course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The course ID this announcement belongs to. Must be a valid course product.', 'power-course' ),
				],
				'post_content'     => [
					'type'        => 'string',
					'description' => \__( 'The announcement content. Allows the same HTML as a WordPress post.', 'power-course' ),
				],
				'post_status'      => [
					'type'        => 'string',
					'enum'        => [ 'publish', 'future', 'draft' ],
					'default'     => 'publish',
					'description' => \__( 'The post status. Use future together with a future post_date to schedule.', 'power-course' ),
				],
				'post_date'        => [
					'type'        => 'string',
					'description' => \__( 'The publish date (Y-m-d H:i:s, site timezone). Defaults to now.', 'power-course' ),
				],
				'visibility'       => [
					'type'        => 'string',
					'enum'        => [ Utils::VISIBILITY_PUBLIC, Utils::VISIBILITY_ENROLLED ],
					'default'     => Utils::VISIBILITY_PUBLIC,
					'description' => \__( 'Visibility: public (everyone) or enrolled (purchasers only).', 'power-course' ),
				],
				'end_at'           => [
					'type'        => [ 'integer', 'string' ],
					'description' => \__( 'Expiration time as a 10-digit Unix timestamp, must be later than post_date. Leave empty to never expire.', 'power-course' ),
				],
			],
			'required'   => [ 'post_title', 'parent_course_id' ],
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
					'description' => \__( 'The ID of the newly created announcement.', 'power-course' ),
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
	 * 執行建立公告
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		// 1. 必填欄位驗證。
		$post_title       = isset( $args['post_title'] ) ? \sanitize_text_field( (string) $args['post_title'] ) : '';
		$parent_course_id = \absint( (int) ( $args['parent_course_id'] ?? 0 ) );

		if ( '' === trim( $post_title ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'post_title is a required field and cannot be empty.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}
		if ( $parent_course_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'parent_course_id is a required field and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}
		if ( ! Utils::is_valid_course( $parent_course_id ) ) {
			return new \WP_Error(
				'mcp_course_not_found',
				\__( 'parent_course_id does not match an existing course product.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		// 2. 組裝建立資料（未提供的欄位交由 Crud::create 套用預設值）。
		$data = [
			'post_title'       => $post_title,
			'parent_course_id' => $parent_course_id,
		];

		if ( isset( $args['post_content'] ) ) {
			$data['post_content'] = \wp_kses_post( (string) $args['post_content'] );
		}
		if ( isset( $args['post_status'] ) ) {
			$data['post_status'] = \sanitize_key( (string) $args['post_status'] );
		}
		if ( isset( $args['post_date'] ) && '' !== $args['post_date'] ) {
			$data['post_date'] = \sanitize_text_field( (string) $args['post_date'] );
		}
		if ( isset( $args['visibility'] ) ) {
			$data['visibility'] = \sanitize_key( (string) $args['visibility'] );
		}
		if ( array_key_exists( 'end_at', $args ) ) {
			$data['end_at'] = is_numeric( $args['end_at'] ) ? (int) $args['end_at'] : '';
		}

		// 3. 複用既有 Crud::create 業務邏輯。
		try {
			$id = Crud::create( $data );
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

		$result = [ 'id' => $id ];

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
	 * 將 Crud::create 拋出的 RuntimeException 轉為對應 HTTP 狀態碼的 WP_Error。
	 *
	 * 參數驗證類錯誤（標題空、課程無效、visibility / end_at 不合法）以 422 回傳；
	 * 寫入失敗（建立公告失敗）以 500 回傳。
	 *
	 * @param \RuntimeException $e 例外物件。
	 * @return \WP_Error
	 */
	private function runtime_exception_to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( \str_contains( $message, '建立公告失敗' ) ) {
			return new \WP_Error( 'mcp_announcement_create_failed', $message, [ 'status' => 500 ] );
		}

		return new \WP_Error( 'mcp_invalid_input', $message, [ 'status' => 422 ] );
	}
}
