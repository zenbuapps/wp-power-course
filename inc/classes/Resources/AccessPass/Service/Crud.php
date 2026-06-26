<?php
/**
 * AccessPass Service Crud（Issue #252）
 *
 * 課程權限包（pc_access_pass CPT）的 Create / Update / Attach / Disable 業務邏輯，
 * 供 REST callback 使用。驗證失敗一律拋 \RuntimeException，由 callback 層轉成 WP_Error。
 *
 * 範圍 / 期限 / 狀態 meta key（對照 erm.dbml access_passes）：
 *   - scope_type：all | category | specific
 *   - limit_type：unlimited | fixed | assigned | follow_subscription
 *   - limit_value / limit_unit：限時模式的數值與單位
 *       - fixed     → limit_value 正整數、limit_unit ∈ day|month|year（相對到期）
 *       - assigned  → limit_value 絕對 Unix timestamp（10 位）、limit_unit = timestamp（指定日期到期）
 *   - access_pass_status：active | disabled
 *   - scope_term_ids：多列 postmeta（category 範圍的 product_cat / product_tag 聯集）
 *   - scope_course_ids：多列 postmeta（specific 範圍的固定課程清單）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;

/**
 * Class Crud
 *
 * 職責：封裝課程權限包的建立、更新、掛載到商品、停用、刪除邏輯。
 */
final class Crud {

	/** @var array<string> 合法的範圍類型 */
	private const SCOPE_TYPES = [ 'all', 'category', 'specific' ];

	/** @var array<string> 合法的期限模式（對齊課程 WatchLimit 模型） */
	private const LIMIT_TYPES = [ 'unlimited', 'fixed', 'assigned', 'follow_subscription' ];

	/** @var array<string> fixed 模式合法的相對時間單位 */
	private const FIXED_UNITS = [ 'day', 'month', 'year' ];

	/** @var int assigned 模式 timestamp 下界（10 位數，2001-09-09 之後） */
	private const ASSIGNED_TIMESTAMP_MIN = 1000000000;

	/** @var int assigned 模式 timestamp 上界（10 位數上限，2286-11-20 之前） */
	private const ASSIGNED_TIMESTAMP_MAX = 9999999999;

	/** @var string active 狀態 */
	private const STATUS_ACTIVE = 'active';

	/** @var string disabled 狀態 */
	private const STATUS_DISABLED = 'disabled';

	/**
	 * 建立課程權限包
	 *
	 * 必要參數：name、scope_type、limit_type
	 * 條件參數：
	 *   - scope_type=category → term_ids（至少一個）
	 *   - scope_type=specific → course_ids（至少一門）
	 *   - limit_type=fixed    → limit_value（正整數）、limit_unit（day|month|year）
	 *   - limit_type=assigned → limit_value（10 位 Unix timestamp）、limit_unit=timestamp
	 *
	 * @param array<string, mixed> $args 權限包資料
	 *
	 * @return int 新建權限包 post ID
	 * @throws \RuntimeException 當參數不合法或建立失敗時拋出
	 */
	public static function create( array $args ): int {
		$name       = \trim( (string) ( $args['name'] ?? '' ) );
		$scope_type = (string) ( $args['scope_type'] ?? '' );
		$limit_type = (string) ( $args['limit_type'] ?? '' );
		$term_ids   = self::sanitize_id_list( $args['term_ids'] ?? [] );
		$course_ids = self::sanitize_id_list( $args['course_ids'] ?? [] );

		// === 前置（參數）驗證 ===
		if ( '' === $name ) {
			throw new \RuntimeException( 'name 不可為空' );
		}
		if ( ! \in_array( $scope_type, self::SCOPE_TYPES, true ) ) {
			throw new \RuntimeException( 'scope_type 必須為 all、category 或 specific' );
		}
		if ( ! \in_array( $limit_type, self::LIMIT_TYPES, true ) ) {
			throw new \RuntimeException( 'limit_type 必須為 unlimited、fixed、assigned 或 follow_subscription' );
		}
		if ( 'category' === $scope_type && empty( $term_ids ) ) {
			throw new \RuntimeException( 'scope_type 為 category 時，term_ids 至少需指定一個' );
		}
		if ( 'specific' === $scope_type && empty( $course_ids ) ) {
			throw new \RuntimeException( 'scope_type 為 specific 時，course_ids 至少需指定一門課程' );
		}

		$limit_value = null;
		$limit_unit  = null;
		if ( 'fixed' === $limit_type ) {
			[ $limit_value, $limit_unit ] = self::validate_fixed( $args );
		}
		if ( 'assigned' === $limit_type ) {
			[ $limit_value, $limit_unit ] = self::validate_assigned( $args );
		}

		// === 建立 CPT ===
		$insert_args = [
			'post_type'   => CPT::POST_TYPE,
			'post_title'  => $name,
			'post_status' => 'publish',
			'post_author' => \get_current_user_id(),
			'meta_input'  => [
				'scope_type'         => $scope_type,
				'limit_type'         => $limit_type,
				'access_pass_status' => self::STATUS_ACTIVE,
			],
		];

		if ( null !== $limit_value ) {
			$insert_args['meta_input']['limit_value'] = $limit_value;
			$insert_args['meta_input']['limit_unit']  = $limit_unit;
		}

		$result = \wp_insert_post( $insert_args, true );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException( '建立課程權限包失敗：' . $result->get_error_message() );
		}
		if ( ! \is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( '建立課程權限包失敗：回傳值不合法' );
		}

		// === 多列 postmeta（範圍內容）===
		if ( 'category' === $scope_type ) {
			self::sync_multi_meta( $result, 'scope_term_ids', $term_ids );
		}
		if ( 'specific' === $scope_type ) {
			self::sync_multi_meta( $result, 'scope_course_ids', $course_ids );
		}

		return $result;
	}

	/**
	 * 更新課程權限包
	 *
	 * 採 partial update：未帶的欄位保持原狀。
	 * 變更 scope_type / term_ids / course_ids 時，重寫對應的多列 postmeta。
	 *
	 * @param int                  $pass_id 權限包 post ID
	 * @param array<string, mixed> $args    更新欄位
	 *
	 * @return int 被更新的權限包 ID
	 * @throws \RuntimeException 當權限包不存在、參數不合法或更新失敗時拋出
	 */
	public static function update( int $pass_id, array $args = [] ): int {
		$post = self::get_pass_post( $pass_id );

		// === 參數驗證 ===
		if ( \array_key_exists( 'name', $args ) ) {
			$name = \trim( (string) $args['name'] );
			if ( '' === $name ) {
				throw new \RuntimeException( 'name 不可為空' );
			}
		}
		if ( \array_key_exists( 'scope_type', $args ) && ! \in_array( (string) $args['scope_type'], self::SCOPE_TYPES, true ) ) {
			throw new \RuntimeException( 'scope_type 必須為 all、category 或 specific' );
		}
		if ( \array_key_exists( 'limit_type', $args ) && ! \in_array( (string) $args['limit_type'], self::LIMIT_TYPES, true ) ) {
			throw new \RuntimeException( 'limit_type 必須為 unlimited、fixed、assigned 或 follow_subscription' );
		}

		// === 更新 CPT 主體（name → post_title）===
		$update_args = [ 'ID' => $pass_id ];
		if ( isset( $name ) ) {
			$update_args['post_title'] = $name;
		}

		if ( \count( $update_args ) > 1 ) {
			$result = \wp_update_post( $update_args, true );
			if ( \is_wp_error( $result ) ) {
				throw new \RuntimeException( '更新課程權限包失敗：' . $result->get_error_message() );
			}
		}

		// === 更新範圍 / 期限 meta ===
		if ( \array_key_exists( 'scope_type', $args ) ) {
			\update_post_meta( $pass_id, 'scope_type', (string) $args['scope_type'] );
		}
		if ( \array_key_exists( 'limit_type', $args ) ) {
			\update_post_meta( $pass_id, 'limit_type', (string) $args['limit_type'] );
		}
		if ( \array_key_exists( 'limit_value', $args ) ) {
			\update_post_meta( $pass_id, 'limit_value', \absint( (int) $args['limit_value'] ) );
		}
		if ( \array_key_exists( 'limit_unit', $args ) ) {
			\update_post_meta( $pass_id, 'limit_unit', \sanitize_text_field( (string) $args['limit_unit'] ) );
		}

		// === 重寫多列 postmeta（範圍內容）===
		if ( \array_key_exists( 'term_ids', $args ) ) {
			self::sync_multi_meta( $pass_id, 'scope_term_ids', self::sanitize_id_list( $args['term_ids'] ) );
		}
		if ( \array_key_exists( 'course_ids', $args ) ) {
			self::sync_multi_meta( $pass_id, 'scope_course_ids', self::sanitize_id_list( $args['course_ids'] ) );
		}

		return $pass_id;
	}

	/**
	 * 將權限包掛載到商品（1 商品 1 包，覆蓋既有掛載）
	 *
	 * 前置（狀態）：
	 *   - 權限包必須存在且為 active（disabled 不可掛新商品）
	 *   - 商品必須存在
	 *
	 * 後置（狀態）：商品 access_pass_id meta = $pass_id。
	 * 與商品的 bind_courses_data 並存、效果並集（不互斥、不覆蓋）。
	 *
	 * @param int $pass_id    權限包 post ID
	 * @param int $product_id 商品 post ID
	 *
	 * @return void
	 * @throws \RuntimeException 當權限包不存在 / 已停用、或商品不存在時拋出
	 */
	public static function attach_to_product( int $pass_id, int $product_id ): void {
		$post = self::get_pass_post( $pass_id );

		// 權限包須為 active（disabled 不可掛新商品）
		$status = (string) ( \get_post_meta( $pass_id, 'access_pass_status', true ) ?: self::STATUS_ACTIVE );
		if ( self::STATUS_ACTIVE !== $status ) {
			throw new \RuntimeException( '已停用的課程權限包不可掛載到新商品' );
		}

		// 商品須存在
		$product = \get_post( $product_id );
		if ( ! $product instanceof \WP_Post || 'product' !== $product->post_type ) {
			throw new \RuntimeException( '商品不存在' );
		}

		// 寫入掛載關係（覆蓋既有；不碰 bind_courses_data）
		\update_post_meta( $product_id, 'access_pass_id', $pass_id );
	}

	/**
	 * 停用課程權限包
	 *
	 * 後置（狀態）：access_pass_status = disabled。
	 * 停用後不可再掛載到新商品（由 attach_to_product 的 active 檢查擋下），
	 * 已購學員的持有關係不受影響（OR 疊加，觀看權保留，屬 R3 Gate 範疇）。
	 *
	 * @param int $pass_id 權限包 post ID
	 *
	 * @return int 被停用的權限包 ID
	 * @throws \RuntimeException 當權限包不存在時拋出
	 */
	public static function disable( int $pass_id ): int {
		self::get_pass_post( $pass_id );

		\update_post_meta( $pass_id, 'access_pass_status', self::STATUS_DISABLED );

		return $pass_id;
	}

	/**
	 * 刪除課程權限包（真正收回已購用戶觀看權）
	 *
	 * 前置（參數）：confirm 必須為 true（二次確認），否則拋例外。
	 * 前置（狀態）：權限包必須存在，否則拋例外。
	 *
	 * 後置（狀態）：
	 *   1. 統計受影響用戶數（COUNT DISTINCT user_id）供回報「將影響 N 位已購用戶」。
	 *   2. 刪除 pc_user_access_pass 中此 pass_id 的所有持有列（收回觀看權）。
	 *   3. wp_delete_post 刪除 CPT。
	 *
	 * 關鍵不變式（ASM-R1）：**絕不**碰 avl_course_ids、**絕不**動 pc_avl_coursemeta。
	 * OR 疊加保證單獨購買 / 逐課綁定的課程權限不受影響（不誤砍其他來源）。
	 *
	 * @param int  $pass_id 權限包 post ID
	 * @param bool $confirm 二次確認旗標（必須為 true）
	 *
	 * @return array{affected_user_count:int} 受影響的 distinct 用戶數
	 * @throws \RuntimeException 當未帶確認旗標、或權限包不存在時拋出
	 */
	public static function delete( int $pass_id, bool $confirm ): array {
		// === 前置（參數）：二次確認 ===
		if ( true !== $confirm ) {
			throw new \RuntimeException( '刪除課程權限包需帶二次確認旗標 confirm=true' );
		}

		// === 前置（狀態）：權限包必須存在 ===
		self::get_pass_post( $pass_id );

		// === 後置（狀態）===
		// 1. 統計受影響用戶數（供 UI 回報「將影響 N 位已購用戶」）
		$affected_user_count = Repository::count_distinct_users_by_pass( $pass_id );

		// 2. 收回持有關係：只刪 pc_user_access_pass 中 pass_id 的列（絕不碰 avl_course_ids / pc_avl_coursemeta）
		Repository::delete_by_pass( $pass_id );

		// 3. 刪除 CPT（force delete，不進垃圾桶以避免殘留 meta 影響後續查詢）
		\wp_delete_post( $pass_id, true );

		// 持有關係異動：失效 Gate request 級快取（同 request 後續判定立即反映）
		Gate::flush_cache();

		return [ 'affected_user_count' => $affected_user_count ];
	}

	/**
	 * 取得權限包 CPT post 物件，找不到則拋例外
	 *
	 * @param int $pass_id 權限包 post ID
	 *
	 * @return \WP_Post
	 * @throws \RuntimeException 當權限包不存在（或非 pc_access_pass）時拋出
	 */
	private static function get_pass_post( int $pass_id ): \WP_Post {
		$post = \get_post( $pass_id );
		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			throw new \RuntimeException( '課程權限包不存在' );
		}
		return $post;
	}

	/**
	 * 驗證 fixed 模式參數（相對到期：購買後 N 天/月/年），回傳清洗後的 [limit_value, limit_unit]
	 *
	 * @param array<string, mixed> $args 權限包資料
	 *
	 * @return array{0:int,1:string} [limit_value, limit_unit]
	 * @throws \RuntimeException 當 limit_value 非正整數、或 limit_unit 非 day|month|year 時拋出
	 */
	private static function validate_fixed( array $args ): array {
		$limit_value = isset( $args['limit_value'] ) ? \absint( (int) $args['limit_value'] ) : 0;
		if ( $limit_value <= 0 ) {
			throw new \RuntimeException( 'limit_type 為 fixed 時，limit_value 必須為正整數' );
		}
		$limit_unit = \sanitize_text_field( (string) ( $args['limit_unit'] ?? '' ) );
		if ( ! \in_array( $limit_unit, self::FIXED_UNITS, true ) ) {
			throw new \RuntimeException( 'limit_type 為 fixed 時，limit_unit 必須為 day、month 或 year' );
		}

		return [ $limit_value, $limit_unit ];
	}

	/**
	 * 驗證 assigned 模式參數（指定日期到期：limit_value 為絕對 Unix timestamp），回傳清洗後的 [limit_value, limit_unit]
	 *
	 * 對齊課程 WatchLimit 的 assigned 語義：limit_value 為 10 位正整數 Unix timestamp、limit_unit 固定 timestamp。
	 * 邊界判斷：須落在 [1000000000, 9999999999]（即 10 位數區間，2001-09-09 ~ 2286-11-20），杜絕誤填毫秒值或秒數溢位。
	 *
	 * @param array<string, mixed> $args 權限包資料
	 *
	 * @return array{0:int,1:string} [limit_value, limit_unit]（limit_unit 恆為 timestamp）
	 * @throws \RuntimeException 當 limit_value 非有效 10 位 Unix timestamp 時拋出
	 */
	private static function validate_assigned( array $args ): array {
		$limit_value = isset( $args['limit_value'] ) ? (int) $args['limit_value'] : 0;
		if ( $limit_value < self::ASSIGNED_TIMESTAMP_MIN || $limit_value > self::ASSIGNED_TIMESTAMP_MAX ) {
			throw new \RuntimeException( 'limit_type 為 assigned 時，limit_value 必須為有效的 10 位 Unix timestamp' );
		}

		return [ $limit_value, 'timestamp' ];
	}

	/**
	 * 同步多列 postmeta（先刪後增，達成「整組覆蓋」語意）
	 *
	 * 用 add_post_meta(..., false) 寫多列，使 get_post_meta(..., false) 可取回完整清單。
	 *
	 * @param int        $pass_id  權限包 post ID
	 * @param string     $meta_key meta key（scope_term_ids | scope_course_ids）
	 * @param array<int> $ids      要寫入的 ID 清單
	 *
	 * @return void
	 */
	private static function sync_multi_meta( int $pass_id, string $meta_key, array $ids ): void {
		\delete_post_meta( $pass_id, $meta_key );
		foreach ( $ids as $id ) {
			\add_post_meta( $pass_id, $meta_key, $id, false );
		}
	}

	/**
	 * 將任意輸入清洗為去重後的正整數陣列
	 *
	 * @param mixed $value 原始輸入（陣列或其他）
	 *
	 * @return array<int>
	 */
	private static function sanitize_id_list( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$ids = \array_map( static fn( $id ): int => \absint( (int) $id ), $value );
		$ids = \array_filter( $ids, static fn( int $id ): bool => $id > 0 );

		return \array_values( \array_unique( $ids ) );
	}
}
