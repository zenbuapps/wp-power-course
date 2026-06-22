<?php
/**
 * MCP Announcement Delete Tool
 *
 * 刪除課程公告，複用 Announcement Crud Service 的刪除邏輯（預設軟刪除至垃圾桶）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Announcement;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Announcement\Service\Crud;

/**
 * Class AnnouncementDeleteTool
 *
 * 對應 MCP ability：power-course/announcement_delete
 */
final class AnnouncementDeleteTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'announcement_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Delete announcement', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Delete a course announcement. By default the announcement is moved to trash; set force to true to permanently delete it.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The announcement ID to delete.', 'power-course' ),
				],
				'force' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => \__( 'When true, permanently delete the announcement instead of moving it to trash.', 'power-course' ),
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
					'description' => \__( 'Whether the announcement was deleted successfully.', 'power-course' ),
				],
				'id'      => [
					'type'        => 'integer',
					'description' => \__( 'The deleted announcement ID.', 'power-course' ),
				],
				'force'   => [
					'type'        => 'boolean',
					'description' => \__( 'Whether the deletion was permanent.', 'power-course' ),
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
	 * 執行刪除公告
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{deleted: bool, id: int, force: bool}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$id = \absint( (int) ( $args['id'] ?? 0 ) );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$force = ! empty( $args['force'] ) && \filter_var( $args['force'], FILTER_VALIDATE_BOOLEAN );

		// 複用既有 Crud::delete 業務邏輯（找不到公告會拋出「公告不存在」）。
		try {
			$deleted = Crud::delete( $id, $force );
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

		if ( ! $deleted ) {
			return new \WP_Error(
				'mcp_announcement_delete_failed',
				\__( 'Failed to delete announcement', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'deleted' => true,
			'id'      => $id,
			'force'   => $force,
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
	 * 將 Crud::delete 拋出的 RuntimeException 轉為對應 HTTP 狀態碼的 WP_Error。
	 *
	 * 公告不存在以 404 回傳；其餘以 422 回傳。
	 *
	 * @param \RuntimeException $e 例外物件。
	 * @return \WP_Error
	 */
	private function runtime_exception_to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( '公告不存在' === $message ) {
			return new \WP_Error( 'mcp_announcement_not_found', $message, [ 'status' => 404 ] );
		}

		return new \WP_Error( 'mcp_invalid_input', $message, [ 'status' => 422 ] );
	}
}
