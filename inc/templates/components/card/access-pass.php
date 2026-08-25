<?php
/**
 * 課程通行證卡片（My Account → 我的通行證）
 *
 * 資料一律由 Service\Holdings 供給——到期有效性走的是 Gate::is_expire_valid()，
 * 與教室的觀看判定同一份邏輯，不會出現「這裡說還有效、教室卻擋下來」。
 *
 * @var array<string, mixed> $args
 */

use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\AccessPass\Service\Scope;

$default_args = [
	'holding' => null,
	// 卡片內最多直接列出幾門課，超過的以「還有 N 門」帶過（all 範圍可能有上百門課）
	'max_courses' => 12,
];

$args = wp_parse_args( $args, $default_args );

[
	'holding'     => $holding,
	'max_courses' => $max_courses,
] = $args;

if ( ! is_array( $holding ) || empty( $holding['pass_id'] ) ) {
	return;
}

$pass_id          = (int) $holding['pass_id'];
$name             = (string) ( $holding['name'] ?? '' );
$limit_type       = (string) ( $holding['limit_type'] ?? 'unlimited' );
$scope_type       = (string) ( $holding['scope_type'] ?? 'all' );
$is_valid         = (bool) ( $holding['is_valid'] ?? false );
$is_expiring_soon = (bool) ( $holding['is_expiring_soon'] ?? false );
$days_remaining   = $holding['days_remaining'] ?? null;
$expire_human     = (string) ( $holding['expire_date_human'] ?? '' );
$course_count     = (int) ( $holding['covered_course_count'] ?? 0 );
$term_names       = [];
foreach ( (array) ( $holding['scope_term_names'] ?? [] ) as $term_name ) {
	$term_names[] = (string) $term_name;
}
$granted_at       = (string) ( $holding['granted_at'] ?? '' );

// ========== 狀態徽章 ==========
if ( ! $is_valid ) {
	$badge_label = esc_html__( 'Expired', 'power-course' );
	$badge_class = 'bg-gray-100 text-gray-500';
} elseif ( $is_expiring_soon ) {
	$badge_label = esc_html__( 'Expiring soon', 'power-course' );
	$badge_class = 'bg-orange-100 text-orange-700';
} else {
	$badge_label = esc_html_x( 'Active', 'access pass status', 'power-course' );
	$badge_class = 'bg-green-100 text-green-700';
}

// ========== 期限文案 ==========
if ( 'unlimited' === $limit_type ) {
	$period_text = esc_html__( 'Permanent', 'power-course' );
} elseif ( 'follow_subscription' === $limit_type ) {
	$next_payment = (string) ( $holding['next_payment_date'] ?? '' );
	if ( ! $is_valid ) {
		$period_text = esc_html__( 'Subscription inactive', 'power-course' );
	} elseif ( $next_payment ) {
		$period_text = sprintf(
			/* translators: %s: 下次扣款日期（Y-m-d） */
			esc_html__( 'Follows subscription, next payment on %s', 'power-course' ),
			esc_html( (string) mysql2date( 'Y-m-d', $next_payment ) )
		);
	} else {
		$period_text = esc_html__( 'Follows subscription', 'power-course' );
	}
} elseif ( ! $is_valid ) {
	$period_text = $expire_human
	? sprintf(
			/* translators: %s: 到期日期（Y-m-d） */
			esc_html__( 'Expired on %s', 'power-course' ),
			esc_html( $expire_human )
		)
	: esc_html__( 'Expired', 'power-course' );
} else {
	$days        = null === $days_remaining ? 0 : (int) $days_remaining;
	$period_text = sprintf(
		/* translators: 1: 剩餘天數, 2: 到期日期（Y-m-d） */
		esc_html(
			_n(
				'%1$d day left (expires on %2$s)',
				'%1$d days left (expires on %2$s)',
				$days,
				'power-course'
			)
		),
		$days,
		esc_html( $expire_human )
	);
}

// ========== 範圍文案 ==========
switch ( $scope_type ) {
	case 'all':
		$scope_text = esc_html__( 'All courses on this site', 'power-course' );
		break;
	case 'category':
		$scope_text = $term_names
		? sprintf(
				/* translators: %s: 分類 / 標籤名稱清單，以頓號分隔 */
				esc_html__( 'Categories: %s', 'power-course' ),
				esc_html( implode( '、', $term_names ) )
			)
		: esc_html__( 'Selected categories', 'power-course' );
		break;
	case 'specific':
		$specific_count = (int) ( $holding['scope_course_count'] ?? 0 );
		$scope_text     = sprintf(
			/* translators: %d: 指定的課程數量 */
			esc_html( _n( '%d selected course', '%d selected courses', $specific_count, 'power-course' ) ),
			$specific_count
		);
		break;
	default:
		$scope_text = '';
}

// ========== 動態範圍提示 ==========
// all / category 是 compute-on-read：日後新上架且落在範圍內的課程會自動涵蓋。
$is_dynamic_scope = in_array( $scope_type, [ 'all', 'category' ], true );

// ========== 涵蓋的課程（伺服器端直接渲染前 N 門，不打 API）==========
$course_list_html = '';
$pass_model       = AccessPass::instance( $pass_id );
if ( $pass_model instanceof AccessPass && $course_count > 0 ) {
	$course_ids = Scope::get_course_ids( $pass_model, (int) $max_courses, 0 );

	foreach ( $course_ids as $course_id ) {
		$course_list_html .= sprintf(
			/*html*/'<li class="py-1"><a class="text-sm text-gray-600 hover:text-primary" href="%1$s">%2$s</a></li>',
			esc_url( (string) get_permalink( $course_id ) ),
			esc_html( (string) get_the_title( $course_id ) )
		);
	}

	$remaining = $course_count - count( $course_ids );
	if ( $remaining > 0 ) {
		$course_list_html .= sprintf(
			/*html*/'<li class="py-1 text-sm text-gray-400">%s</li>',
			sprintf(
				/* translators: %d: 未列出的課程數量 */
				esc_html( _n( 'and %d more course', 'and %d more courses', $remaining, 'power-course' ) ),
				$remaining
			)
		);
	}
}

printf(
	/*html*/'
<div class="pc-access-pass-card border border-gray-200 rounded-lg p-4 mb-4 %11$s">
	<div class="flex items-start justify-between gap-2 mb-2">
		<h3 class="text-base font-bold m-0">%1$s</h3>
		<span class="text-xs px-2 py-1 rounded-full text-nowrap %2$s">%3$s</span>
	</div>
	<p class="text-sm text-gray-500 m-0 mb-1">%4$s</p>
	%5$s
	<div class="flex gap-2 items-center mb-2">
		<span class="text-gray-400 text-xs text-nowrap">%6$s</span>
		<span class="text-xs text-nowrap font-bold %7$s">%8$s</span>
	</div>
	%9$s
	%10$s
</div>
',
	esc_html( $name ),
	esc_attr( $badge_class ),
	$badge_label,
	$scope_text,
	$is_dynamic_scope
		? sprintf(
			/*html*/'<p class="text-xs text-gray-400 m-0 mb-2">%s</p>',
			esc_html__( 'New courses added to this scope are covered automatically.', 'power-course' )
		)
		: '',
	esc_html__( 'Watch period', 'power-course' ),
	$is_expiring_soon ? 'text-orange-600' : ( $is_valid ? 'text-primary' : 'text-gray-400' ),
	$period_text,
	$course_count > 0
		? sprintf(
			/*html*/'
	<details class="pc-access-pass-card__courses">
		<summary class="text-sm text-primary cursor-pointer">%1$s</summary>
		<ul class="mt-2 mb-0 pl-4 list-disc">%2$s</ul>
	</details>',
			sprintf(
				/* translators: %d: 通行證涵蓋的課程數量 */
				esc_html( _n( 'Covers %d course', 'Covers %d courses', $course_count, 'power-course' ) ),
				$course_count
			),
			$course_list_html
		)
		: sprintf(
			/*html*/'<p class="text-sm text-gray-400 m-0">%s</p>',
			esc_html__( 'No courses are covered by this access pass yet.', 'power-course' )
		),
	$granted_at
		? sprintf(
			/*html*/'<p class="text-xs text-gray-400 m-0 mt-2">%s</p>',
			sprintf(
				/* translators: %s: 取得日期（Y-m-d） */
				esc_html__( 'Granted on %s', 'power-course' ),
				esc_html( (string) mysql2date( 'Y-m-d', $granted_at ) )
			)
		)
		: '',
	$is_valid ? '' : 'opacity-60'
);
