<?php
/**
 * AccessPass Gate（Issue #252）— keystone 觀看判定（compute-on-read）
 *
 * 即時計算使用者對某課程是否擁有「有效權限包」涵蓋的觀看權，不展開課程 id 到 avl_course_ids。
 * 由 Utils\Course::is_avl() 與 is_expired() 以 OR 疊加方式接入，是 11 個觀看判定消費點的一致性樞紐。
 *
 * 判定 = 該 user 持有的每個 pass 中，存在「scope 涵蓋 $course_id」且「expire 有效」者：
 *   - scope：all 恆真；category → 課程 term ∈（pass term_ids ∪ get_term_children 子分類）；specific → $course_id ∈ pass course_ids
 *   - expire：permanent → true；limited → now < expire_timestamp；
 *     follow_subscription → wcs_get_subscription()->has_status(['active','pending-cancel'])，回 null → false
 *
 * 安全 / 效能：
 *   - request 級 memoize（class static 陣列）：以 user_id 快取持有列、以 pass_id 快取 Model 與展開後的範圍；
 *     寫入持有關係時由 Repository 主動失效（避免 wp_cache_flush_group 在部分 object cache 為 no-op 的不確定性）
 *   - 全程 try/catch（ASM-G3 fail-closed）：任何例外一律回 false，避免熱路徑因 pass 資料異常導致整頁 500
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;

/**
 * Class Gate
 * 課程權限包觀看判定（compute-on-read）。全靜態；memoize 存於 class static 陣列（request 生命週期），
 * 持有關係寫入 / 刪除時由 Repository 主動失效（flush_cache）。
 */
final class Gate {

	/**
	 * request 級 memoize：使用者持有列（user_id → [pass_id => expire_date]）
	 *
	 * 以 class static 取代 wp_cache，確保失效行為在所有 object cache 實作下都可預期
	 * （wp_cache_flush_group 在部分實作為 no-op）。持有關係寫入時由 Repository 主動失效。
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private static array $held_passes_cache = [];

	/**
	 * request 級 memoize：權限包 Model（pass_id → AccessPass|null）
	 *
	 * @var array<int, AccessPass|null>
	 */
	private static array $pass_model_cache = [];

	/**
	 * request 級 memoize：展開後的分類 term 範圍（pass_id → term id 清單）
	 *
	 * @var array<int, array<int>>
	 */
	private static array $expanded_terms_cache = [];

	/**
	 * 判定使用者是否擁有「有效權限包」涵蓋指定課程的觀看權
	 *
	 * @param int $user_id   學員 WordPress user ID（0/未登入 → 一律 false）
	 * @param int $course_id 課程（商品）post ID
	 *
	 * @return bool 有任一「scope 涵蓋且 expire 有效」的持有 pass → true；否則 false
	 */
	public static function user_has_valid_pass_for_course( int $user_id, int $course_id ): bool {
		try {
			// 未登入一律不可觀看（與 is_avl 既有語義一致）
			if ( $user_id <= 0 || $course_id <= 0 ) {
				return false;
			}

			$pass_ids = self::get_held_pass_ids( $user_id );
			if ( empty( $pass_ids ) ) {
				return false;
			}

			foreach ( $pass_ids as $pass_id => $expire_date ) {
				$pass = self::get_pass_model( (int) $pass_id );
				// 持有列殘留但 CPT 已被刪 → 視為無效，跳過該列（靜默正確降級）
				if ( ! $pass instanceof AccessPass ) {
					continue;
				}

				if ( ! self::scope_covers_course( $pass, $course_id ) ) {
					continue;
				}

				if ( ! self::is_expire_valid( $pass, $expire_date ) ) {
					continue;
				}

				// 命中：scope 涵蓋且 expire 有效
				return true;
			}

			return false;
		} catch ( \Throwable $e ) {
			// fail-closed：判定在熱路徑，任何例外一律拒絕觀看（不開放未授權，不讓整頁掛掉）
			return false;
		}
	}

	/**
	 * 取得使用者持有的 pass_id → expire_date 映射（request 級 memoize）
	 *
	 * 同一 pass 若有多列持有（理論上 upsert 已去重），保留任一列的 expire_date。
	 *
	 * @param int $user_id 學員 WordPress user ID
	 *
	 * @return array<int, string|null> key=pass_id，value=expire_date 表達式
	 */
	private static function get_held_pass_ids( int $user_id ): array {
		if ( isset( self::$held_passes_cache[ $user_id ] ) ) {
			return self::$held_passes_cache[ $user_id ];
		}

		$rows     = Repository::find_by_user( $user_id );
		$pass_map = [];
		foreach ( $rows as $row ) {
			$pass_id = (int) ( $row->pass_id ?? 0 );
			if ( $pass_id <= 0 ) {
				continue;
			}
			$pass_map[ $pass_id ] = isset( $row->expire_date ) ? (string) $row->expire_date : null;
		}

		self::$held_passes_cache[ $user_id ] = $pass_map;
		return $pass_map;
	}

	/**
	 * 載入權限包 Model（request 級 memoize）
	 *
	 * @param int $pass_id 權限包 post ID
	 *
	 * @return AccessPass|null 找不到（或非 pc_access_pass）時回傳 null
	 */
	private static function get_pass_model( int $pass_id ): ?AccessPass {
		if ( \array_key_exists( $pass_id, self::$pass_model_cache ) ) {
			return self::$pass_model_cache[ $pass_id ];
		}

		$pass                              = AccessPass::instance( $pass_id );
		self::$pass_model_cache[ $pass_id ] = $pass;
		return $pass;
	}

	/**
	 * 判定 pass 的 scope 是否涵蓋指定課程
	 *
	 *   - all：恆真
	 *   - category：課程的 product_cat term ∈（pass term_ids ∪ 其子孫分類）；product_tag 為非階層不展開
	 *   - specific：$course_id ∈ pass course_ids
	 *
	 * @param AccessPass $pass      權限包 Model
	 * @param int        $course_id 課程（商品）post ID
	 *
	 * @return bool
	 */
	private static function scope_covers_course( AccessPass $pass, int $course_id ): bool {
		switch ( $pass->scope_type ) {
			case 'all':
				return true;

			case 'specific':
				return \in_array( $course_id, $pass->course_ids, true );

			case 'category':
				return self::category_covers_course( $pass->id, $pass->term_ids, $course_id );

			default:
				return false;
		}
	}

	/**
	 * 判定分類範圍是否涵蓋課程（含子分類展開）
	 *
	 * 展開後的「所選 term ∪ 其子孫」與課程實際綁定的 term（product_cat + product_tag）取交集；
	 * 任一命中即涵蓋。展開結果以 pass_id 為 key 做 request 級 memoize。
	 *
	 * @param int        $pass_id   權限包 post ID（memoize key）
	 * @param array<int> $term_ids  pass 設定的分類 term id 清單
	 * @param int        $course_id 課程（商品）post ID
	 *
	 * @return bool
	 */
	private static function category_covers_course( int $pass_id, array $term_ids, int $course_id ): bool {
		if ( empty( $term_ids ) ) {
			return false;
		}

		$expanded = self::get_expanded_term_ids( $pass_id, $term_ids );
		if ( empty( $expanded ) ) {
			return false;
		}

		// 課程實際綁定的 term（product_cat 階層 + product_tag 非階層）
		$course_term_ids = self::get_course_term_ids( $course_id );
		if ( empty( $course_term_ids ) ) {
			return false;
		}

		return [] !== \array_intersect( $expanded, $course_term_ids );
	}

	/**
	 * 取得「所選 term ∪ 其子孫分類」展開後的 term id 清單（request 級 memoize）
	 *
	 * 僅對 product_cat（階層分類）以 get_term_children 展開子孫；product_tag 為非階層不展開（原樣保留）。
	 *
	 * @param int        $pass_id  權限包 post ID（memoize key）
	 * @param array<int> $term_ids pass 設定的分類 term id 清單
	 *
	 * @return array<int> 展開後去重的 term id 清單
	 */
	private static function get_expanded_term_ids( int $pass_id, array $term_ids ): array {
		if ( isset( self::$expanded_terms_cache[ $pass_id ] ) ) {
			return self::$expanded_terms_cache[ $pass_id ];
		}

		$expanded = [];
		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 ) {
				continue;
			}
			$expanded[] = $term_id;

			// 僅 product_cat 展開子孫（product_tag 非階層，get_term_children 會回空）
			$children = \get_term_children( $term_id, 'product_cat' );
			if ( \is_array( $children ) ) {
				foreach ( $children as $child_id ) {
					$expanded[] = (int) $child_id;
				}
			}
		}

		$expanded                             = \array_values( \array_unique( $expanded ) );
		self::$expanded_terms_cache[ $pass_id ] = $expanded;
		return $expanded;
	}

	/**
	 * 取得課程實際綁定的 term id（product_cat + product_tag）
	 *
	 * @param int $course_id 課程（商品）post ID
	 *
	 * @return array<int>
	 */
	private static function get_course_term_ids( int $course_id ): array {
		$term_ids = [];
		foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
			$terms = \get_the_terms( $course_id, $taxonomy );
			if ( \is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$term_ids[] = (int) $term->term_id;
					}
				}
			}
		}
		return \array_values( \array_unique( $term_ids ) );
	}

	/**
	 * 判定 pass 持有列的 expire 是否仍有效
	 *
	 *   - permanent：恆有效（不看 expire_date）
	 *   - limited：expire_date 為 timestamp，now < expire 才有效（0/空 → 視為無有效期限 → 失效）
	 *   - follow_subscription：expire_date = "subscription_{id}"，
	 *     wcs_get_subscription()->has_status(['active','pending-cancel']) 才有效；查無訂閱 → 失效
	 *
	 * @param AccessPass  $pass        權限包 Model（提供 limit_mode）
	 * @param string|null $expire_date 持有列的到期表達式
	 *
	 * @return bool
	 */
	private static function is_expire_valid( AccessPass $pass, ?string $expire_date ): bool {
		switch ( $pass->limit_mode ) {
			case 'permanent':
				return true;

			case 'follow_subscription':
				return self::is_subscription_valid( (string) $expire_date );

			case 'limited':
				$timestamp = (int) $expire_date;
				if ( $timestamp <= 0 ) {
					// 限時模式卻無有效到期 timestamp → 視為失效
					return false;
				}
				return \time() < $timestamp;

			default:
				return false;
		}
	}

	/**
	 * 判定「跟隨訂閱」持有列對應的訂閱是否仍允許觀看
	 *
	 * 對齊 Utils\Course::is_expired() L588-594：active / pending-cancel 視為未到期；
	 * 訂閱被刪（wcs_get_subscription 回 null）或環境無 WC Subscriptions → 失效。
	 *
	 * @param string $expire_date 到期表達式（"subscription_{id}"）
	 *
	 * @return bool
	 */
	private static function is_subscription_valid( string $expire_date ): bool {
		if ( ! \str_starts_with( $expire_date, 'subscription_' ) ) {
			return false;
		}
		if ( ! \function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		$subscription_id = (int) \str_replace( 'subscription_', '', $expire_date );
		if ( $subscription_id <= 0 ) {
			return false;
		}

		$subscription = \wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return false;
		}

		return $subscription->has_status( [ 'active', 'pending-cancel' ] );
	}

	/**
	 * 失效 request 級 memoize（持有關係異動 / 刪除 pass 後呼叫，確保同 request 後續判定取得最新狀態）
	 *
	 * @param int|null $user_id 指定失效某使用者的持有列快取；null 時僅清群組級可清項目
	 *
	 * @return void
	 */
	public static function flush_cache( ?int $user_id = null ): void {
		if ( null !== $user_id ) {
			unset( self::$held_passes_cache[ $user_id ] );
		} else {
			self::$held_passes_cache = [];
		}

		// pass 本身可能被新增 / 編輯 / 刪除（測試環境 DB 回滾後 id 亦會重用），
		// 一律清空 per-pass 快取，確保後續判定載入最新 Model 與展開範圍。
		self::$pass_model_cache     = [];
		self::$expanded_terms_cache = [];
	}

	/**
	 * 取得 CPT post type（供測試 / 外部對照）
	 *
	 * @return string
	 */
	public static function get_post_type(): string {
		return CPT::POST_TYPE;
	}
}
