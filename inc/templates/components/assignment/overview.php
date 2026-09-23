<?php
/**
 * 課程作業總覽（Issue #131 P8）
 *
 * DEC-15：放在教室作業區塊頂部、可收合，依章節順序列出「整門課程」的每一份作業與狀態。
 * 依 2026-09-22 team-lead 裁決，總覽項目只呈現「標題／所屬章節／狀態徽章」，
 * **不**渲染繳交表單（沒有 textarea／file input／繳交按鈕），避免把上傳入口複製到
 * 尚未解鎖的章節、放大攻擊面；鎖定章節額外顯示鎖定訊息。
 *
 * 用 <details open> 實作收合，不依賴 JS —— bundle 沒載到時仍是展開可讀的。
 */

$default_args = [
	'rows'       => [],
	'total'      => 0,
	'pass_count' => 0,
	'pass_rate'  => 0,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'rows'       => $rows,
	'total'      => $total,
	'pass_count' => $pass_count,
	'pass_rate'  => $pass_rate,
] = $args;

if ( ! is_array( $rows ) || ! $rows ) {
	return;
}

// 狀態 → 徽章樣式
$badge_class_map = [
	'none'    => 'pc-badge-ghost',
	'pending' => 'pc-badge-accent',
	'pass'    => 'pc-badge-success',
	'fail'    => 'pc-badge-error',
];

echo /*html*/'<details class="pc-assignment-overview mb-6" open>';

printf(
	/*html*/'<summary class="pc-assignment-overview__toggle cursor-pointer font-bold">%s</summary>',
	esc_html__( 'Course assignment overview', 'power-course' )
);

echo /*html*/'<div class="pc-assignment-overview__body mt-3">';

echo /*html*/'<div class="pc-assignment-overview__stats flex flex-wrap gap-x-4 gap-y-1 text-sm mb-3">';
printf(
	/*html*/'<span class="pc-assignment-overview__stat">%s</span>',
	esc_html(
		sprintf(
			/* translators: %s: 作業總數 */
			_x( 'Total assignments: %s', 'assignment overview', 'power-course' ),
			(string) (int) $total
		)
	)
);
printf(
	/*html*/'<span class="pc-assignment-overview__stat">%s</span>',
	esc_html(
		sprintf(
			/* translators: %s: 已通過的作業份數 */
			_x( 'Passed: %s', 'assignment overview', 'power-course' ),
			(string) (int) $pass_count
		)
	)
);
printf(
	/*html*/'<span class="pc-assignment-overview__stat">%s</span>',
	esc_html(
		sprintf(
			/* translators: %s: 通過率（含百分比符號） */
			_x( 'Pass rate: %s', 'assignment overview', 'power-course' ),
			( (string) (int) $pass_rate ) . '%'
		)
	)
);
echo /*html*/'</div>';

echo /*html*/'<ul class="pc-assignment-overview__items flex flex-col gap-y-2">';

foreach ( $rows as $row ) {
	if ( ! is_array( $row ) ) {
		continue;
	}

	$assignment = is_array( $row['assignment'] ?? null ) ? $row['assignment'] : [];
	$item_id     = (int) ( $assignment['id'] ?? 0 );
	if ( ! $item_id ) {
		continue;
	}

	$item_status = (string) ( $row['status'] ?? 'none' );
	$badge_class = $badge_class_map[ $item_status ] ?? 'pc-badge-ghost';
	$is_locked   = ! empty( $row['is_locked'] );

	printf(
		/*html*/'<li class="pc-assignment-overview__item flex flex-wrap items-center gap-x-2 text-sm" data-assignment-id="%1$s" data-locked="%2$s">',
		esc_attr( (string) $item_id ),
		esc_attr( $is_locked ? 'true' : 'false' )
	);

	printf(
		/*html*/'<span class="pc-assignment-overview__item-title font-medium">%s</span>',
		esc_html( (string) ( $assignment['title'] ?? '' ) )
	);
	printf(
		/*html*/'<span class="pc-assignment-overview__item-chapter opacity-60">%s</span>',
		esc_html( (string) ( $row['chapter_title'] ?? '' ) )
	);
	printf(
		/*html*/'<span class="pc-assignment-overview__item-status pc-badge pc-badge-sm %1$s">%2$s</span>',
		esc_attr( $badge_class ),
		esc_html( (string) ( $row['status_label'] ?? '' ) )
	);

	if ( $is_locked ) {
		printf(
			/*html*/'<span class="pc-assignment-overview__lock-message opacity-60">%s</span>',
			esc_html( (string) ( $row['lock_message'] ?? '' ) )
		);
	}

	echo /*html*/'</li>';
}

echo /*html*/'</ul>';
echo /*html*/'</div>';
echo /*html*/'</details>';
