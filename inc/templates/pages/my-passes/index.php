<?php
/**
 * My Account 我的通行證
 *
 * 列出目前登入者持有的所有課程通行證：剩餘天數、快到期警示、涵蓋哪些課程。
 * 已失效的也會列出（置底、半透明）——學員需要知道「我買過但已到期」，
 * 直接濾掉會讓他以為紀錄消失了。
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\AccessPass\Service\Holdings;

$current_user_id = get_current_user_id();
$holdings        = $current_user_id ? Holdings::get_for_user( $current_user_id ) : [];

if ( ! $holdings ) {
	printf(
		/*html*/'
<div class="pc-access-passes--empty text-center py-8">
	<p class="text-gray-500 mb-4">%1$s</p>
	<a href="%2$s" class="text-primary">%3$s</a>
</div>
',
		esc_html__( 'You do not have any access passes yet.', 'power-course' ),
		esc_url( (string) get_permalink( (int) wc_get_page_id( 'shop' ) ) ),
		esc_html__( 'Browse courses', 'power-course' )
	);
	return;
}

// 快到期的張數，拿來在頁首提醒（Holdings 已依「有效者優先、到期日近者優先」排序）
$expiring_count = count(
	array_filter(
		$holdings,
		static fn( array $holding ): bool => ! empty( $holding['is_expiring_soon'] )
	)
);

if ( $expiring_count > 0 ) {
	printf(
		/*html*/'<div class="pc-access-passes__notice bg-orange-50 border border-orange-200 text-orange-700 rounded-lg px-4 py-3 mb-4 text-sm">%s</div>',
		sprintf(
			/* translators: %d: 即將到期的通行證張數 */
			esc_html(
				_n(
					'%d of your access passes is expiring soon. Renew it to keep watching.',
					'%d of your access passes are expiring soon. Renew them to keep watching.',
					$expiring_count,
					'power-course'
				)
			),
			$expiring_count
		)
	);
}

echo '<div class="pc-access-passes">';

foreach ( $holdings as $holding ) {
	Plugin::load_template(
		'card/access-pass',
		[
			'holding' => $holding,
		]
	);
}

echo '</div>';
