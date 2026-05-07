<?php
/**
 * Comment Query Service
 * 留言查詢服務層 — 提供 list 相關邏輯，供 REST Api callback 與 MCP tool 共用
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Comment\Service;

use J7\WpUtils\Classes\WP;

/**
 * Class Query
 * 留言查詢服務，封裝 get_comments 的查詢邏輯
 */
final class Query {

	/**
	 * 列出留言
	 *
	 * 封裝 get_comments() 的查詢與分頁邏輯，回傳 comments 陣列與分頁資訊。
	 *
	 * @param array<string, mixed> $params 查詢參數：
	 *  - number       int    每頁筆數（預設 10）
	 *  - paged        int    頁數（預設 1）
	 *  - post_id      int    所屬 post ID
	 *  - type         string 留言類型（comment|review），預設 'review'
	 *  - user_id      int    限定用戶 ID
	 *  - hierarchical string 階層模式（threaded|flat），預設 'threaded'
	 *  - status       string 狀態（approve|hold|spam|trash|all），預設 'approve'
	 *                        ※ 管理員自動升級為 'all'
	 * @param callable             $formatter 單筆 comment 的格式化函式，簽名：fn(\WP_Comment $c, int $depth, array $args): array
	 * @return array{comments: array<int, array<string, mixed>>, total: int, total_pages: int, current_page: int, page_size: int}
	 */
	public static function list( array $params, callable $formatter ): array {
		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'number'       => 10,
			'paged'        => 1,
			'post_id'      => 0,
			'type'         => 'review',
			'user_id'      => '',
			'hierarchical' => 'threaded',
			'status'       => 'approve',
		];

		$args = \wp_parse_args( $params, $default_args );

		if ( \current_user_can( 'manage_woocommerce' ) ) {
			$args['status'] = 'all';
		}

		/** @var \WP_Comment[] $comments */
		$comments = \get_comments( $args );
		$comments = \array_values( $comments );

		$formatted_comments = array_map(
			static fn( $comment ) => $formatter(
				$comment,
				0,
				[
					'status' => $args['status'],
				]
			),
			$comments
		);

		$count_args = array_merge( $args, [ 'count' => true ] );
		unset( $count_args['paged'] );
		/** @var int $total */
		$total       = \get_comments( $count_args );
		$number      = (int) $args['number'] > 0 ? (int) $args['number'] : 10;
		$total_pages = (int) floor( $total / $number ) + 1;

		return [
			'comments'     => $formatted_comments,
			'total'        => (int) $total,
			'total_pages'  => $total_pages,
			'current_page' => (int) $args['paged'],
			'page_size'    => $number,
		];
	}
}
