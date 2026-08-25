<?php
/**
 * AccessPass Holdings（持有關係的展示層）— Issue #252 補完
 *
 * 把 pc_user_access_pass 的原始列，加工成前台 / 後台都能直接渲染的資料：
 * 到期時間、剩餘天數、是否快到期、來源（訂單 / 訂閱 / 手動發放）、涵蓋課程數。
 *
 * ⚠️ 有效性判定一律委派 Gate::is_expire_valid()，**不得**在此另寫一份到期邏輯——
 * 否則會出現「前台說還有效、教室卻擋下來」的分歧。這是本模組最容易埋雷的地方。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class Holdings
 * 使用者 ↔ 通行證持有關係的查詢與格式化。
 */
final class Holdings {

	/** @var string 來源：後台手動發放 */
	public const SOURCE_MANUAL = 'manual';

	/** @var string 來源：一次性訂單開通 */
	public const SOURCE_ORDER = 'order';

	/** @var string 來源：訂閱開通（首期付款，無 source_order_id） */
	public const SOURCE_SUBSCRIPTION = 'subscription';

	/**
	 * 取得指定使用者持有的所有通行證（前台「我的通行證」用）
	 *
	 * 已失效的通行證仍會回傳（is_valid=false），由呈現層決定要不要顯示——
	 * 學員需要知道「我買過但已到期」，直接濾掉會讓他以為紀錄消失了。
	 *
	 * @param int $user_id 學員 user ID
	 *
	 * @return array<int, array<string, mixed>> 依「有效者優先、到期日近者優先」排序
	 */
	public static function get_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$rows      = Repository::find_by_user( $user_id );
		$threshold = self::get_expiring_threshold_days();
		$items     = [];

		foreach ( $rows as $row ) {
			$pass_id = (int) ( $row->pass_id ?? 0 );
			$pass    = AccessPass::instance( $pass_id );

			// 持有列殘留但通行證 CPT 已被刪 → 跳過（與 Gate 的靜默降級一致）
			if ( ! $pass instanceof AccessPass ) {
				continue;
			}

			$items[] = self::format_row( $row, $pass, $threshold, true );
		}

		\usort(
			$items,
			static function ( array $a, array $b ): int {
				// 有效的排前面
				if ( $a['is_valid'] !== $b['is_valid'] ) {
					return $a['is_valid'] ? -1 : 1;
				}
				// 都有到期日 → 近者優先；無到期日（永久 / 跟隨訂閱）排最後
				$a_ts = $a['expire_timestamp'] ?? \PHP_INT_MAX;
				$b_ts = $b['expire_timestamp'] ?? \PHP_INT_MAX;
				return $a_ts <=> $b_ts;
			}
		);

		return $items;
	}

	/**
	 * 取得指定通行證的持有學員名單（後台「持有學員」Tab 用，分頁）
	 *
	 * @param int $pass_id  權限包 post ID
	 * @param int $page     頁碼（1 起算）
	 * @param int $per_page 每頁筆數
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	public static function get_holders_of_pass( int $pass_id, int $page = 1, int $per_page = 20 ): array {
		$pass = AccessPass::instance( $pass_id );
		if ( ! $pass instanceof AccessPass ) {
			return [
				'items'       => [],
				'total'       => 0,
				'total_pages' => 0,
			];
		}

		$page     = \max( 1, $page );
		$per_page = \max( 1, $per_page );
		$total    = Repository::count_by_pass( $pass_id );
		$rows     = Repository::find_by_pass( $pass_id, $per_page, ( $page - 1 ) * $per_page );

		$threshold = self::get_expiring_threshold_days();
		$items     = [];

		foreach ( $rows as $row ) {
			$item    = self::format_row( $row, $pass, $threshold, false );
			$items[] = \array_merge( $item, self::format_user_fields( $row ) );
		}

		return [
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) \ceil( $total / $per_page ),
		];
	}

	/**
	 * 「即將到期」的天數門檻（前台警示與到期預警信共用同一個設定）
	 *
	 * @return int 門檻天數（至少 1）
	 */
	public static function get_expiring_threshold_days(): int {
		$days = (int) Settings::instance()->access_pass_expiring_days;
		return $days > 0 ? $days : 7;
	}

	/**
	 * 把單一持有列格式化成展示資料
	 *
	 * @param object     $row            pc_user_access_pass 的資料庫列
	 * @param AccessPass $pass           對應的通行證 Model
	 * @param int        $threshold_days 「即將到期」門檻天數
	 * @param bool       $with_scope     是否附上範圍資訊（涵蓋課程數 / 分類名稱）——後台名單不需要，省一次查詢
	 *
	 * @return array<string, mixed>
	 */
	private static function format_row( object $row, AccessPass $pass, int $threshold_days, bool $with_scope ): array {
		$expire_date = isset( $row->expire_date ) ? (string) $row->expire_date : null;
		$expire      = self::resolve_expire( $pass, $expire_date );

		$is_expiring_soon = $expire['is_valid']
		&& null !== $expire['days_remaining']
		&& $expire['days_remaining'] <= $threshold_days;

		$item = [
			'id'                  => (int) ( $row->id ?? 0 ),
			'user_id'             => (int) ( $row->user_id ?? 0 ),
			'pass_id'             => $pass->id,
			'name'                => $pass->name,
			'scope_type'          => $pass->scope_type,
			'limit_type'          => $pass->limit_type,
			'status'              => $pass->status,
			'expire_date'         => $expire_date,
			'expire_timestamp'    => $expire['expire_timestamp'],
			'expire_date_human'   => $expire['expire_date_human'],
			'days_remaining'      => $expire['days_remaining'],
			'is_valid'            => $expire['is_valid'],
			'is_expiring_soon'    => $is_expiring_soon,
			'subscription_id'     => $expire['subscription_id'],
			'subscription_status' => $expire['subscription_status'],
			'next_payment_date'   => $expire['next_payment_date'],
			'source'              => self::resolve_source( $row ),
			'source_order_id'     => isset( $row->source_order_id ) ? (int) $row->source_order_id : null,
			'granted_by'          => isset( $row->granted_by ) ? (int) $row->granted_by : null,
			'granted_at'          => isset( $row->granted_at ) ? (string) $row->granted_at : null,
		];

		if ( $with_scope ) {
			$item['covered_course_count'] = Scope::count_courses( $pass );
			$item['scope_term_names']     = self::get_term_names( $pass );
			$item['scope_course_count']   = \count( $pass->course_ids );
		}

		return $item;
	}

	/**
	 * 解析到期資訊
	 *
	 * 有效性一律走 Gate::is_expire_valid()（與觀看判定同一份邏輯）；本方法只額外算出
	 * 「人看得懂」的欄位：到期 timestamp、剩餘天數、訂閱狀態與下次扣款日。
	 *
	 *   - unlimited           → 無到期日，days_remaining = null
	 *   - fixed / assigned    → expire_date 即絕對 timestamp
	 *   - follow_subscription → 無固定到期日，改回報訂閱狀態與下次扣款日
	 *
	 * @param AccessPass  $pass        通行證 Model
	 * @param string|null $expire_date 持有列的到期表達式
	 *
	 * @return array{expire_timestamp:int|null, expire_date_human:string, days_remaining:int|null, is_valid:bool, subscription_id:int|null, subscription_status:string|null, next_payment_date:string|null}
	 */
	private static function resolve_expire( AccessPass $pass, ?string $expire_date ): array {
		$result = [
			'expire_timestamp'    => null,
			'expire_date_human'   => '',
			'days_remaining'      => null,
			'is_valid'            => Gate::is_expire_valid( $pass, $expire_date ),
			'subscription_id'     => null,
			'subscription_status' => null,
			'next_payment_date'   => null,
		];

		if ( 'follow_subscription' === $pass->limit_type ) {
			return \array_merge( $result, self::resolve_subscription_info( (string) $expire_date ) );
		}

		if ( 'unlimited' === $pass->limit_type ) {
			return $result;
		}

		// fixed / assigned：expire_date 皆為絕對 timestamp
		$timestamp = (int) $expire_date;
		if ( $timestamp <= 0 ) {
			return $result;
		}

		$result['expire_timestamp']  = $timestamp;
		$result['expire_date_human'] = (string) \wp_date( 'Y-m-d', $timestamp );
		$result['days_remaining']    = \max( 0, (int) \ceil( ( $timestamp - \time() ) / \DAY_IN_SECONDS ) );

		return $result;
	}

	/**
	 * 解析「跟隨訂閱」的訂閱狀態與下次扣款日
	 *
	 * @param string $expire_date 到期表達式（"subscription_{id}"）
	 *
	 * @return array{subscription_id:int|null, subscription_status:string|null, next_payment_date:string|null}
	 */
	private static function resolve_subscription_info( string $expire_date ): array {
		$empty = [
			'subscription_id'     => null,
			'subscription_status' => null,
			'next_payment_date'   => null,
		];

		if ( ! \str_starts_with( $expire_date, 'subscription_' ) || ! \function_exists( 'wcs_get_subscription' ) ) {
			return $empty;
		}

		$subscription_id = (int) \str_replace( 'subscription_', '', $expire_date );
		if ( $subscription_id <= 0 ) {
			return $empty;
		}

		$subscription = \wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			// 訂閱被刪 → 已失效（Gate 也是這樣判），但仍回報 id 供後台追溯
			return \array_merge( $empty, [ 'subscription_id' => $subscription_id ] );
		}

		$next_payment = $subscription->get_date( 'next_payment' );

		return [
			'subscription_id'     => $subscription_id,
			'subscription_status' => (string) $subscription->get_status(),
			'next_payment_date'   => $next_payment ? (string) $next_payment : null,
		];
	}

	/**
	 * 判定持有關係的來源
	 *
	 * 判定規則：granted_by 有值 → 後台手動發放；否則 source_order_id 有值 → 一次性訂單；
	 * 兩者皆空 → 訂閱首期開通（Grant::on_subscription_payment_complete 兩個欄位都傳 null）。
	 *
	 * @param object $row pc_user_access_pass 的資料庫列
	 *
	 * @return string self::SOURCE_* 之一
	 */
	private static function resolve_source( object $row ): string {
		if ( ! empty( $row->granted_by ) ) {
			return self::SOURCE_MANUAL;
		}
		if ( ! empty( $row->source_order_id ) ) {
			return self::SOURCE_ORDER;
		}
		return self::SOURCE_SUBSCRIPTION;
	}

	/**
	 * 取得持有者與發放者的顯示資訊（後台名單用）
	 *
	 * @param object $row pc_user_access_pass 的資料庫列
	 *
	 * @return array<string, mixed>
	 */
	private static function format_user_fields( object $row ): array {
		$user_id = (int) ( $row->user_id ?? 0 );
		$user    = $user_id > 0 ? \get_userdata( $user_id ) : false;

		$granted_by      = isset( $row->granted_by ) ? (int) $row->granted_by : 0;
		$granted_by_user = $granted_by > 0 ? \get_userdata( $granted_by ) : false;

		return [
			'display_name'    => $user ? (string) $user->display_name : '',
			'user_email'      => $user ? (string) $user->user_email : '',
			'user_login'      => $user ? (string) $user->user_login : '',
			'avatar_url'      => $user_id > 0 ? (string) \get_avatar_url( $user_id ) : '',
			'granted_by_name' => $granted_by_user ? (string) $granted_by_user->display_name : '',
		];
	}

	/**
	 * 取得 category 範圍的分類 / 標籤名稱（供前台顯示「分類：設計、行銷」）
	 *
	 * 只回**站主實際勾選**的 term 名稱，不含自動展開的子分類——展開結果對學員沒有意義，
	 * 且數量可能很大。實際涵蓋範圍以 covered_course_count 表達。
	 *
	 * @param AccessPass $pass 通行證 Model
	 *
	 * @return array<int, string>
	 */
	private static function get_term_names( AccessPass $pass ): array {
		if ( 'category' !== $pass->scope_type ) {
			return [];
		}

		$names = [];
		foreach ( $pass->term_ids as $term_id ) {
			$term = \get_term( (int) $term_id );
			if ( $term instanceof \WP_Term ) {
				$names[] = (string) $term->name;
			}
		}

		return $names;
	}
}
