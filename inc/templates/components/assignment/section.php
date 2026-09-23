<?php
/**
 * 教室作業區塊（Issue #131 P8）
 *
 * 對應規格 specs/ui/教室作業區塊.md、計劃 §9 P8 與 §4 DEC-15：
 *   - 伺服器端渲染，區塊頂部是可收合的「課程作業總覽」，其後才是本章節自己的作業卡片
 *   - 學員走 Service\MyQuery（含自己的繳交）；管理員預覽走 Service\Query（無繳交、表單全停用）
 *   - 線性觀看鎖定與否在伺服器端算好，輸出成卡片 / 總覽項目的 data-locked
 *
 * 載入點：inc/templates/pages/classroom/body.php 的 the_content() 之後、留言之前。
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Service\MyQuery;
use J7\PowerCourse\Resources\Assignment\Service\Query as AssignmentQuery;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;
use J7\PowerCourse\Utils\LinearViewing;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
	'chapter' => $GLOBALS['chapter'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
	'chapter' => $chapter,
] = $args;

if ( ! ( $product instanceof \WC_Product ) || ! ( $chapter instanceof \WP_Post ) ) {
	return;
}

$course_id  = (int) $product->get_id();
$chapter_id = (int) $chapter->ID;
$user_id    = (int) get_current_user_id();

if ( ! $user_id ) {
	return;
}

// 管理員預覽模式：改用管理端查詢（Service\Query），所有表單與按鈕停用。
//
// 刻意不用 CourseUtils::is_admin_preview()——它是 `manage_woocommerce && ! is_avl()`，
// 課程免費時管理員自己也會被判成 is_avl，於是回傳 false，教室就會把管理員當學員渲染出
// 可繳交的表單。判定改成單純的能力檢查，與 LinearViewing::is_exempt()
// （「管理員即使是學員也應免除限制」）同一條規則。
$is_readonly = current_user_can( 'manage_woocommerce' );

/** @var array<int, array{assignment: array<string, mixed>, my_submission: array<string, mixed>|null}> $items */
$items = [];

if ( $is_readonly ) {
	foreach ( AssignmentQuery::list_by_course( $course_id ) as $assignment_model ) {
		$items[] = [
			'assignment'    => $assignment_model->to_array(),
			'my_submission' => null,
		];
	}
} else {
	try {
		$items = MyQuery::list( [ 'course_id' => $course_id ], $user_id );
	} catch ( \Throwable $e ) {
		// 沒有有效觀看權（或課程解析不出來）時整個區塊不渲染，不洩漏作業是否存在。
		return;
	}
}

if ( ! $items ) {
	return;
}

// 線性觀看：一次算好整門課的鎖定章節，避免逐份作業重算解鎖狀態。
$locked_chapter_ids = [];
if ( LinearViewing::is_enabled( $course_id ) && ! LinearViewing::is_exempt( $user_id ) ) {
	$unlock_status      = LinearViewing::get_unlock_status( $course_id, $user_id );
	$locked_chapter_ids = $unlock_status['locked_ids'];
}

// 與 Service\Submission::assert_chapter_unlocked() 的 403 訊息同一個 msgid，前後端用語一致。
$lock_message = __( 'This chapter is not yet unlocked. Please complete the previous chapters first.', 'power-course' );
$now          = time();

$rows       = [];
$pass_count = 0;

foreach ( $items as $item ) {
	$assignment = $item['assignment'];
	if ( ! $assignment ) {
		continue;
	}

	$submission = $item['my_submission'];
	$row_status = null === $submission ? 'none' : (string) ( $submission['status'] ?? 'pending' );

	if ( 'pass' === $row_status ) {
		++$pass_count;
	}

	$row_chapter_id = (int) ( $assignment['chapter_id'] ?? 0 );
	$due_at         = (int) ( $assignment['due_at'] ?? 0 );

	$rows[] = [
		'assignment'    => $assignment,
		'submission'    => $submission,
		'status'        => $row_status,
		'status_label'  => 'none' === $row_status
			? _x( 'Not submitted', 'assignment submission status', 'power-course' )
			: AssignmentUtils::status_label( $row_status ),
		'chapter_id'    => $row_chapter_id,
		'chapter_title' => (string) get_the_title( $row_chapter_id ),
		'is_locked'     => in_array( $row_chapter_id, $locked_chapter_ids, true ),
		// 未繳交且已過截止時間才算逾期（逾期仍可繳交，只是加警示標記）
		'is_overdue'    => $due_at > 0 && $due_at < $now && 'none' === $row_status,
		'lock_message'  => $lock_message,
		'is_readonly'   => $is_readonly,
	];
}

if ( ! $rows ) {
	return;
}

// $rows 非空已在上方早退保證，這裡不需要除零防護
$total     = count( $rows );
$pass_rate = (int) round( $pass_count / $total * 100 );

printf(
	/*html*/'<section id="pc-classroom-assignments" class="pc-classroom-assignments my-8" data-course-id="%1$s" data-chapter-id="%2$s" data-rest-url="%3$s" data-readonly="%4$s">',
	esc_attr( (string) $course_id ),
	esc_attr( (string) $chapter_id ),
	esc_attr( esc_url_raw( rest_url( 'power-course/' ) ) ),
	esc_attr( $is_readonly ? 'yes' : 'no' )
);

// 課程作業總覽固定是區塊的第一個子元素（DEC-15）
Plugin::load_template(
	'assignment/overview',
	[
		'rows'       => $rows,
		'total'      => $total,
		'pass_count' => $pass_count,
		'pass_rate'  => $pass_rate,
	]
);

$chapter_rows = array_values(
	array_filter(
		$rows,
		static fn( array $row ): bool => $row['chapter_id'] === $chapter_id
	)
);

if ( $chapter_rows ) {
	echo /*html*/'<div class="pc-assignment-list">';
	printf(
		/*html*/'<h3 class="pc-assignment-list__title text-base font-bold mb-3">%s</h3>',
		esc_html__( 'Chapter assignments', 'power-course' )
	);
	foreach ( $chapter_rows as $row ) {
		Plugin::load_template( 'assignment/card', $row );
	}
	echo /*html*/'</div>';
}

echo /*html*/'</section>';
