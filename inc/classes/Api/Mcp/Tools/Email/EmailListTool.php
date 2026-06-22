<?php
/**
 * MCP Email List Tool
 *
 * 列出 Email 通知模板（pe_email CPT），支援分頁與狀態篩選。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Email;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;

/**
 * Class EmailListTool
 *
 * 對應 MCP ability：power-course/email_list
 */
final class EmailListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'email_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'List email templates', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'List course notification email templates with pagination and status filtering. Returns template id, name, subject and trigger timing.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => \__( 'Number of templates per page (1 ~ 100).', 'power-course' ),
				],
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( 'Page number.', 'power-course' ),
				],
				'post_status'    => [
					'type'        => 'string',
					'enum'        => [ 'any', 'publish', 'draft', 'trash' ],
					'default'     => 'any',
					'description' => \__( 'Filter by template status.', 'power-course' ),
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
				'emails'       => [
					'type'        => 'array',
					'description' => \__( 'Email template list. Each item contains id, name, subject, status and trigger_at.', 'power-course' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'         => [ 'type' => 'string' ],
							'name'       => [ 'type' => 'string' ],
							'subject'    => [ 'type' => 'string' ],
							'status'     => [ 'type' => 'string' ],
							'trigger_at' => [ 'type' => 'string' ],
						],
					],
				],
				'total'        => [
					'type'        => 'integer',
					'description' => \__( 'Total number of matching templates.', 'power-course' ),
				],
				'total_pages'  => [
					'type'        => 'integer',
					'description' => \__( 'Total number of pages.', 'power-course' ),
				],
				'current_page' => [
					'type'        => 'integer',
					'description' => \__( 'Current page number.', 'power-course' ),
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
	 * 執行列出 Email 模板
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{emails: list<EmailResource>, total: int, total_pages: int, current_page: int}
	 */
	protected function execute( array $args ): array {
		$per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

		$allowed_status = [ 'any', 'publish', 'draft', 'trash' ];
		$post_status    = isset( $args['post_status'] ) ? \sanitize_key( (string) $args['post_status'] ) : 'any';
		if ( ! \in_array( $post_status, $allowed_status, true ) ) {
			$post_status = 'any';
		}

		$query = new \WP_Query(
			[
				'post_type'      => EmailCPT::POST_TYPE,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'post_status'    => $post_status,
				'fields'         => 'ids',
				'orderby'        => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
			]
		);

		/** @var array<int> $post_ids */
		$post_ids = $query->posts;

		// 以 API 格式（array）建立 Email 資源，避免巢狀物件無法序列化
		$emails = array_values(
			array_map(
				static fn( int $post_id ): EmailResource => new EmailResource( $post_id, false, true ),
				$post_ids
			)
		);

		return [
			'emails'       => $emails,
			'total'        => (int) $query->found_posts,
			'total_pages'  => (int) $query->max_num_pages,
			'current_page' => $paged,
		];
	}
}
