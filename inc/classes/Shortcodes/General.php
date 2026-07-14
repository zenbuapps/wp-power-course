<?php
/**
 * General Shortcodes
 */

declare(strict_types=1);

namespace J7\PowerCourse\Shortcodes;

use J7\PowerCourse\BundleProduct\Helper;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class General
 */
final class General {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 所有短碼
	 *
	 * @var array<string>
	 */
	public static array $shortcodes = [
		'pc_courses',
		'pc_my_courses',
		'pc_simple_card',
		'pc_bundle_card',
	];

	/**
	 * 每頁課程數上限
	 *
	 * 由於 courses-shortcode-page 是公開端點（登出訪客也能打），limit 必須有上界，
	 * 避免 limit=999999 這種請求把整站商品一次撈進記憶體。
	 * 100 與後台短代碼產生器的 limit 上限一致，正常設定不會被夾到。
	 */
	public const MAX_LIMIT = 100;

	/** Constructor */
	public function __construct() {
		foreach (self::$shortcodes as $shortcode) {
			// @phpstan-ignore-next-line
			\add_shortcode($shortcode, [ __CLASS__, "{$shortcode}_callback" ]);
		}
	}

	/**
	 * 查詢並渲染某一頁課程卡片（shortcode 首頁渲染與 AJAX 取頁共用同一條查詢/渲染路徑）
	 *
	 * 安全性：
	 * - 一律強制 status=['publish'] + visibility='visible'，不接受呼叫方覆寫（公開端點防護）。
	 * - 空字串的 category/tag/include/exclude 會 unset，避免污染 wc_get_products 查詢。
	 * - exclude_avl_courses 的排除清單由後端依當前登入者即時計算，不外洩 avl_course_ids。
	 *
	 * @param array<string, mixed> $params 短代碼／AJAX 參數
	 *                                     （limit, page, columns, category, tag, include, exclude, orderby, order, exclude_avl_courses）。
	 * @return array{html:string, total:int, total_pages:int, current_page:int, columns:int}
	 */
	public static function get_courses_page( array $params ): array {

		$default_args = [
			'status'              => [ 'publish' ],
			'visibility'          => 'visible',
			'paginate'            => true,
			'limit'               => 12,
			'page'                => 1,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'meta_key'            => '_is_course',
			'meta_value'          => 'yes',
			'exclude_avl_courses' => false,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		// 強制只查 publish + visible，不接受外部覆寫（公開端點防護）。
		$args['status']     = [ 'publish' ];
		$args['visibility'] = 'visible';
		$args['meta_key']   = '_is_course';
		$args['meta_value'] = 'yes';
		$args['paginate']   = true;

		// page 正規化：至少為 1（防負數 / 0）。
		$page_val      = (int) ( $args['page'] ?? 1 );
		$page_val      = max( 1, $page_val );
		$args['page']  = $page_val;

		// limit 正規化：夾在 1 ~ MAX_LIMIT。
		// 首屏與 AJAX 翻頁共用同一個上界，兩邊的 total_pages 才不會算出不同答案。
		$limit_val     = (int) ( $args['limit'] ?? 12 );
		$args['limit'] = min( self::MAX_LIMIT, max( 1, $limit_val ) );

		$exclude_avl_courses_val = (bool) ( $args['exclude_avl_courses'] ?? false );
		unset($args['exclude_avl_courses']);

		$columns_val = (int) ( $args['columns'] ?? 3 );
		$columns_val = max( 1, $columns_val );
		unset($args['columns']);

		$array_keys_to_process = [ 'include', 'exclude', 'tag', 'category' ];
		foreach ($array_keys_to_process as $key) {
			if (!isset($args[ $key ])) {
				continue;
			}
			// 空字串參數污染防護：空字串一律 unset，避免誤過濾查詢。
			if (is_string($args[ $key ]) && '' === trim($args[ $key ])) {
				unset($args[ $key ]);
				continue;
			}
			if (is_string($args[ $key ])) {
				$args[ $key ] = explode(',', str_replace(' ', '', $args[ $key ]));
			}
			if (( $key === 'include' || $key === 'exclude' ) && is_array($args[ $key ])) {
				$args[ $key ] = array_filter(array_map(fn( $v ) => (int) $v, $args[ $key ]));
			}
			// 過濾後若為空陣列也 unset。
			if (is_array($args[ $key ]) && empty($args[ $key ])) {
				unset($args[ $key ]);
			}
		}

		$final_excluded_ids = $args['exclude'] ?? [];

		if ( $exclude_avl_courses_val ) {
			$current_user_id     = \get_current_user_id();
			$user_avl_course_ids = CourseUtils::get_avl_courses_by_user( $current_user_id, true );
			if (!empty($user_avl_course_ids)) {
				$final_excluded_ids = array_merge($final_excluded_ids, $user_avl_course_ids);
			}
		}

		if (!empty($final_excluded_ids)) {
			$args['exclude'] = array_unique($final_excluded_ids);
		} else {
			unset($args['exclude']);
		}

		/** @var object{total:int,max_num_pages:int,products:array<int,\WC_Product>} $results */
		$results     = \wc_get_products( $args );
		$total       = (int) $results->total;
		$total_pages = (int) $results->max_num_pages;

		$products = $results->products;

		$html = Plugin::load_template(
			'list/pricing',
			[
				'products' => $products,
				'columns'  => $columns_val,
			],
			false
		);

		return [
			'html'         => (string) $html,
			'total'        => $total,
			'total_pages'  => $total_pages,
			'current_page' => $page_val,
			'columns'      => $columns_val,
		];
	}

	/**
	 * 課程列表短碼 pc_courses callback
	 *
	 * 首屏渲染第 1 頁卡片，並輸出含 data-* 屬性的容器供前台 JS（CoursesListApp）
	 * 進行純 AJAX 傳統頁碼分頁。所有 data 屬性值經 esc_attr() 跳脫；
	 * exclude_avl_courses 僅以布林旗標傳遞，不外洩 avl_course_ids。
	 *
	 * @param array<string, mixed> $params 短碼參數
	 * @return string
	 */
	public static function pc_courses_callback( array $params ): string {

		// 取得原始短代碼參數（保留供 data 屬性與 AJAX 翻頁沿用）。
		$limit               = (int) ( $params['limit'] ?? 12 );
		$limit               = min( self::MAX_LIMIT, max( 1, $limit ) );
		$columns             = (int) ( $params['columns'] ?? 3 );
		$columns             = max( 1, $columns );
		$orderby             = (string) ( $params['orderby'] ?? 'date' );
		$order               = (string) ( $params['order'] ?? 'DESC' );
		$category            = (string) ( $params['category'] ?? '' );
		$tag                 = (string) ( $params['tag'] ?? '' );
		$include             = (string) ( $params['include'] ?? '' );
		$exclude             = (string) ( $params['exclude'] ?? '' );
		$exclude_avl_courses = ! empty( $params['exclude_avl_courses'] )
		&& \filter_var( $params['exclude_avl_courses'], FILTER_VALIDATE_BOOLEAN );

		$page = self::get_courses_page(
			[
				'limit'               => $limit,
				'page'                => 1,
				'columns'             => $columns,
				'orderby'             => $orderby,
				'order'               => $order,
				'category'            => $category,
				'tag'                 => $tag,
				'include'             => $include,
				'exclude'             => $exclude,
				'exclude_avl_courses' => $exclude_avl_courses,
			]
		);

		ob_start();
		?>
		<div class="pc-courses"
			data-limit="<?php echo \esc_attr( (string) $limit ); ?>"
			data-columns="<?php echo \esc_attr( (string) $columns ); ?>"
			data-orderby="<?php echo \esc_attr( $orderby ); ?>"
			data-order="<?php echo \esc_attr( $order ); ?>"
			data-category="<?php echo \esc_attr( $category ); ?>"
			data-tag="<?php echo \esc_attr( $tag ); ?>"
			data-include="<?php echo \esc_attr( $include ); ?>"
			data-exclude="<?php echo \esc_attr( $exclude ); ?>"
			data-exclude_avl_courses="<?php echo \esc_attr( $exclude_avl_courses ? 'true' : 'false' ); ?>"
			data-total="<?php echo \esc_attr( (string) $page['total'] ); ?>"
			data-total-pages="<?php echo \esc_attr( (string) $page['total_pages'] ); ?>"
			data-current-page="<?php echo \esc_attr( (string) $page['current_page'] ); ?>">
			<div class="pc-courses__list"><?php echo $page['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="pc-courses__pagination"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 我的課程短碼 pc_my_courses callback
	 *
	 * @param ?array{} $params 短碼參數
	 * @return string
	 */
	public static function pc_my_courses_callback( ?array $params ): string {
		$html = Plugin::load_template(
			'my-account',
			null,
			false
		);

		return (string) $html;
	}

	/**
	 * 簡單課程卡片短碼 pc_simple_card_callback
	 *
	 * @param array{product_id:int|string} $params 短碼參數
	 * @return string
	 */
	public static function pc_simple_card_callback( array $params ): string {
		$default_args = [
			'product_id' => 0,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$product = \wc_get_product( $args['product_id'] );

		if ( ! ( $product instanceof \WC_Product ) ) {
			return '《' . esc_html__( 'Product not found', 'power-course' ) . '》';
		}

		if (in_array($product->get_type(), [ 'simple','subscription' ], true)) {
			return (string) Plugin::safe_get(
			'card/single-product',
			[
				'product' => $product,
			],
				false
				);
		}

		return '《' . esc_html__( 'Product is not a simple product', 'power-course' ) . '》';
	}

	/**
	 * 銷售方案卡片短碼 pc_bundle_card_callback
	 *
	 * @param array{product_id:int|string} $params 短碼參數
	 * @return string
	 */
	public static function pc_bundle_card_callback( array $params ): string {
		$default_args = [
			'product_id' => 0,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$product = \wc_get_product( $args['product_id'] );

		if ( ! ( $product instanceof \WC_Product ) ) {
			return '《' . esc_html__( 'Product not found', 'power-course' ) . '》';
		}

		$helper = Helper::instance( $product );
		if ( $helper?->is_bundle_product ) {
			return (string) Plugin::safe_get(
				'card/bundle-product',
				[
					'product' => $product,
				],
				false
				);
		}

		return '《' . esc_html__( 'Product is not a bundle', 'power-course' ) . '》';
	}
}
