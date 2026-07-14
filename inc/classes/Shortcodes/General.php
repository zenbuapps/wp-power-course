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
	 * 課程分類 / 標籤參數 → WooCommerce taxonomy 對照表
	 *
	 * @var array<string, string>
	 */
	private const TAXONOMY_MAP = [
		'category' => 'product_cat',
		'tag'      => 'product_tag',
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

		// 課程分類 / 標籤：term ID 與 slug 兩種寫法都要吃，統一正規化成 slug（Issue #254）。
		//
		// 為什麼一定要正規化：wc_get_products() 是以 'field' => 'slug' 去查 term
		// （見 WC_Product_Data_Store_CPT::get_wp_query_args()），直接把 term ID 餵進去
		// 永遠查不到東西、回 0 筆。而後台短代碼產生器輸出的正是
		// [pc_courses product_category_id="16"]（term ID），站長也可能手寫 slug，兩種都得支援。
		foreach ( self::TAXONOMY_MAP as $key => $taxonomy ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}

			/** @var array<int|string> $requested_terms */
			$requested_terms = (array) $args[ $key ];
			$slugs           = self::resolve_term_slugs( $requested_terms, $taxonomy );

			if ( ! $slugs ) {
				// 呼叫端「明確要求」了過濾條件，但一個 term 都解析不出來（例如清單只有空字串）。
				// 這時必須回空集合：若把條件 unset 掉，查詢會退化成「不過濾」而回傳全站課程——
				// 那正是 Issue #254 的病灶模式（過濾靜默失效）。寧可回空，也不要回全站。
				return self::empty_page( $page_val, $columns_val );
			}

			$args[ $key ] = $slugs;
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
	 * 將 term ID / slug 混合清單正規化為 slug 清單（Issue #254）
	 *
	 * 判斷規則：
	 * - 純數字 → 視為 term ID，查回它的 slug。
	 *   查不到（term 已刪除 / ID 屬於別的 taxonomy / get_term 回 WP_Error）時，
	 *   退而把原字串當 slug 用——這樣「slug 剛好是數字」的分類也不會被誤殺，
	 *   而真的不存在的 ID 會變成一個查無此 term 的 slug，wc_get_products 回 0 筆（安全的結果）。
	 * - 非純數字 → 直接視為 slug（含中文的 percent-encoded slug，例如 %e4%b8%8a%e8%a1%a3）。
	 *
	 * 任何情況都不 fatal；解析不出東西時回空陣列，由呼叫端決定（回空集合，而非回全站課程）。
	 *
	 * @param array<int|string> $terms    term ID 或 slug 的混合清單。
	 * @param string            $taxonomy 分類法（product_cat / product_tag）。
	 * @return array<string> 正規化後的 slug 清單（已去重）。
	 */
	private static function resolve_term_slugs( array $terms, string $taxonomy ): array {
		$slugs = [];

		foreach ( $terms as $term ) {
			$term = trim( (string) $term );

			if ( '' === $term ) {
				continue;
			}

			if ( ! \ctype_digit( $term ) ) {
				// 非純數字 → 當 slug 用。
				$slugs[] = $term;
				continue;
			}

			// 純數字 → 先當 term ID 查 slug；查不到就退回把原字串當 slug。
			$term_object = \get_term( (int) $term, $taxonomy );
			$slugs[]     = ( $term_object instanceof \WP_Term ) ? $term_object->slug : $term;
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * 合併多組逗號分隔的 term 參數（Issue #254）
	 *
	 * 用於把 category（slug 寫法）與 product_category_id（term ID 寫法）合併成同一份清單，
	 * 交給 get_courses_page() 統一正規化。去除空白項並去重。
	 *
	 * @param string ...$values 逗號分隔的 term 清單。
	 * @return string 合併後的逗號分隔清單。
	 */
	private static function merge_term_params( string ...$values ): string {
		$terms = [];

		foreach ( $values as $value ) {
			foreach ( explode( ',', $value ) as $term ) {
				$term = trim( $term );
				if ( '' !== $term ) {
					$terms[] = $term;
				}
			}
		}

		return implode( ',', array_unique( $terms ) );
	}

	/**
	 * 產生「查無課程」的空結果頁（Issue #254）
	 *
	 * 用於「呼叫端有指定過濾條件、但條件解析不出任何 term」的情境。
	 * 刻意不走 wc_get_products：直接回 total=0，確保絕不會退化成回傳全站課程。
	 *
	 * @param int $page    目前頁碼。
	 * @param int $columns 欄數。
	 * @return array{html:string, total:int, total_pages:int, current_page:int, columns:int}
	 */
	private static function empty_page( int $page, int $columns ): array {
		$html = Plugin::load_template(
			'list/pricing',
			[
				'products' => [],
				'columns'  => $columns,
			],
			false
		);

		return [
			'html'         => (string) $html,
			'total'        => 0,
			'total_pages'  => 0,
			'current_page' => $page,
			'columns'      => $columns,
		];
	}

	/**
	 * 課程列表短碼 pc_courses callback
	 *
	 * 首屏渲染第 1 頁卡片，並輸出含 data-* 屬性的容器供前台 JS（CoursesListApp）
	 * 進行純 AJAX 傳統頁碼分頁。所有 data 屬性值經 esc_attr() 跳脫；
	 * exclude_avl_courses 僅以布林旗標傳遞，不外洩 avl_course_ids。
	 *
	 * 分類 / 標籤支援兩種寫法（Issue #254）：
	 * - category / tag：term slug（含中文的 percent-encoded slug）。
	 * - product_category_id / product_tag_id：term ID（後台短代碼產生器輸出的就是這種）。
	 * 兩者會被合併後一起塞進 data-category / data-tag，讓 AJAX 翻頁沿用同一組過濾條件。
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
		$category            = self::merge_term_params(
			(string) ( $params['category'] ?? '' ),
			(string) ( $params['product_category_id'] ?? '' )
		);
		$tag                 = self::merge_term_params(
			(string) ( $params['tag'] ?? '' ),
			(string) ( $params['product_tag_id'] ?? '' )
		);
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
