<?php
/**
 * Shortcode API
 */

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Shortcodes\General;

/**
 * Shortcode Api
 */
final class Shortcode extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * 每一支端點的 permission_callback 都「明確」指定，不留 null。
	 *
	 * 為什麼不能留 null：ApiBase::register_apis() 是用 is_callable() 判斷的——
	 * null 不是 callable，會 fallback 成 ApiBase::permission_callback()
	 * （= manage_options | manage_woocommerce），也就是「管理員限定」。
	 * ApiBase 的 docblock 卻寫「預設是 __return_true，即不做任何權限檢查」，
	 * 與實際行為相反。寫 null 的人以為是公開，實際上是鎖死——
	 * courses-shortcode-page 就是這樣讓登出訪客吃 401、前台永遠卡骨架屏的（Issue #236 遺留債）。
	 *
	 * @var array{endpoint: string, method: string, permission_callback: callable}[]
	 */
	protected $apis = [];

	/** Constructor */
	public function __construct() {
		$this->apis = [
			[
				'endpoint'            => 'shortcode',
				'method'              => 'get',

				/*
				 * 管理員限定，且「絕對不可」改成 __return_true。
				 *
				 * 這支端點會對「外部傳入的任意字串」執行 do_shortcode()，等同開放任意短代碼執行：
				 * 站上任何外掛註冊的短代碼都能被匿名觸發（含會讀資料、送信、寫入的短代碼），
				 * 公開等於直接開一個資訊洩漏 / 任意短代碼執行面。
				 * 它只服務後台「短代碼產生器」的即時預覽，前台完全用不到。
				 */
				'permission_callback' => [ $this, 'permission_callback' ],
			],
			[
				'endpoint'            => 'courses-shortcode-page',
				'method'              => 'get',

				/*
				 * 前台 [pc_courses] 的 AJAX 翻頁端點：登出訪客本來就該能翻頁，故明確公開。
				 *
				 * 公開的安全前提（三道）：
				 * 1. 不執行任意短代碼——只用固定的白名單參數呼叫 General::get_courses_page()。
				 * 2. get_courses_page() 強制 status=publish + visibility=visible，不接受外部覆寫，
				 *    故訪客不可能藉此撈到草稿 / 隱藏商品。
				 * 3. 所有輸入都經過正規化：page/limit/columns 走 positive_int_param()，
				 *    orderby 走白名單，order 只收 ASC/DESC，category/tag 逐項 sanitize_title()。
				 */
				'permission_callback' => '__return_true',
			],
		];

		parent::__construct();
	}

	/**
	 * 允許的 orderby 白名單
	 *
	 * 由於 courses-shortcode-page 是公開端點，orderby 會一路流進 WP_Query 的 ORDER BY。
	 * WP_Query 自己雖然也有 allowed keys 檢查，但不能把「上游沒驗證」這件事外包給下游——
	 * 這裡直接白名單，非清單內的值一律退回預設 date。
	 *
	 * 清單內容 = 後台短代碼產生器提供的選項（ID / name / rand / date / modified）
	 * ＋ WooCommerce 常用的商品排序鍵。
	 *
	 * @var array<string>
	 */
	private const ALLOWED_ORDERBY = [
		'ID',
		'id',
		'name',
		'title',
		'slug',
		'date',
		'modified',
		'rand',
		'menu_order',
		'price',
		'popularity',
		'rating',
		'comment_count',
		'include',
	];

	/**
	 * 獲取選項
	 *
	 * 管理員限定端點（見 $apis 內的說明）：會對外部傳入的字串執行 do_shortcode()。
	 *
	 * @param \WP_REST_Request $request REST請求對象。
	 * @return \WP_REST_Response 返回包含選項資料的REST響應對象。
	 */
	public function get_shortcode_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params    = $request->get_params();
		$shortcode = \sanitize_text_field( (string) ( $params['shortcode'] ?? '' ) );

		$shortcode_content = \do_shortcode( $shortcode, true );

		return new \WP_REST_Response(
			[
				'code'    => 'get_shortcode_success',
				'message' => __( 'Shortcode retrieved successfully', 'power-course' ),
				'data'    => $shortcode_content,
			],
			200
		);
	}

	/**
	 * 課程列表 AJAX 分頁端點（公開）
	 *
	 * 沿用 [pc_courses] 短代碼既有查詢參數（category / tag / orderby / exclude_avl_courses 等）
	 * 再加上 page，回傳對應頁的課程卡片 HTML 與分頁資訊。
	 *
	 * 安全性：
	 * - 第一行呼叫 nocache_headers()（Issue #216 規範），避免邊緣快取回傳 stale 資料。
	 * - 僅查 publish + visible 課程（沿用 General::get_courses_page 預設，不接受外部覆寫 status/visibility）。
	 * - page / limit / columns 經 positive_int_param() 正規化（limit 另有上界，防 limit=999999）。
	 * - orderby 走 ALLOWED_ORDERBY 白名單；order 只收 ASC / DESC。
	 * - category / tag 逐一 sanitize_title() 以保留中文等 percent-encoded term slug（Issue #254）。
	 *
	 * @param \WP_REST_Request $request REST 請求對象。
	 * @return \WP_REST_Response 含 html / total / total_pages / current_page 的回應。
	 */
	public function get_courses_shortcode_page_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$p = $request->get_params();

		$order = \strtoupper( $this->string_param( $p['order'] ?? null, 'DESC' ) );
		$order = \in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

		$orderby = $this->string_param( $p['orderby'] ?? null, 'date' );
		$orderby = \in_array( $orderby, self::ALLOWED_ORDERBY, true ) ? $orderby : 'date';

		$params = [
			'page'                => $this->positive_int_param( $p['page'] ?? null, 1, PHP_INT_MAX ),
			'limit'               => $this->positive_int_param( $p['limit'] ?? null, 12, General::MAX_LIMIT ),
			'columns'             => $this->positive_int_param( $p['columns'] ?? null, 3, PHP_INT_MAX ),
			'orderby'             => $orderby,
			'order'               => $order,
			'category'            => $this->sanitize_slug_list( $this->string_param( $p['category'] ?? null, '' ) ),
			'tag'                 => $this->sanitize_slug_list( $this->string_param( $p['tag'] ?? null, '' ) ),
			'include'             => \sanitize_text_field( $this->string_param( $p['include'] ?? null, '' ) ),
			'exclude'             => \sanitize_text_field( $this->string_param( $p['exclude'] ?? null, '' ) ),
			'exclude_avl_courses' => \filter_var( $p['exclude_avl_courses'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		];

		$page = General::get_courses_page( $params );

		return new \WP_REST_Response(
			[
				'code'    => 'get_courses_shortcode_page_success',
				'message' => __( 'Courses page retrieved successfully', 'power-course' ),
				'data'    => [
					'html'         => $page['html'],
					'total'        => $page['total'],
					'total_pages'  => $page['total_pages'],
					'current_page' => $page['current_page'],
				],
			],
			200
		);
	}

	/**
	 * 從 REST 參數安全取出字串
	 *
	 * $request->get_params() 回傳 array<string, mixed>，值可能是陣列（?category[]=x）。
	 * 直接 (string) 轉型陣列會得到字面的 "Array" 並噴 notice，故非純量一律退回預設值。
	 *
	 * @param mixed  $value   原始參數值。
	 * @param string $default 取不到純量時的預設值。
	 * @return string
	 */
	private function string_param( mixed $value, string $default ): string {
		return \is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * 從 REST 參數安全取出正整數並夾在 1 ~ $max
	 *
	 * 同時解掉 PHPStan level 9 的 `absint(mixed)` 告警——先用 is_numeric() 把 mixed
	 * 收斂成 int|string|float，absint() 才拿得到它預期的型別。
	 *
	 * @param mixed $value   原始參數值。
	 * @param int   $default 取不到有效正整數時的預設值。
	 * @param int   $max     上界。
	 * @return int
	 */
	private function positive_int_param( mixed $value, int $default, int $max ): int {
		$int = \is_numeric( $value ) ? \absint( $value ) : 0;

		if ( $int < 1 ) {
			$int = $default;
		}

		return \min( $int, $max );
	}

	/**
	 * 將逗號分隔的 term slug 清單逐一 sanitize_title()（Issue #254）
	 *
	 * Category / tag 參數是逗號分隔的 term slug 清單。sanitize_text_field() 會把
	 * percent-encoded octets（例：中文分類「上衣」slug 為 %e4%b8%8a%e8%a1%a3）當非法
	 * 字元剝除、清成空字串，導致中文分類／標籤在 AJAX 翻頁時過濾靜默失效、回傳全部課程。
	 * sanitize_title() 是 WordPress 產生 term slug 用的同一函式，會正確保留 %xx，
	 * 故逐一取代 sanitize_text_field()。
	 *
	 * @param string $value 逗號分隔的 term slug 清單，例如 "shirts,%e4%b8%8a%e8%a1%a3"。
	 * @return string 清理後的逗號分隔 term slug 清單；輸入為空字串時回傳空字串。
	 */
	private function sanitize_slug_list( string $value ): string {
		$slugs = \array_filter( \array_map( 'sanitize_title', \explode( ',', $value ) ) );
		return \implode( ',', $slugs );
	}
}
