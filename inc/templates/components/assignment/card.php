<?php
/**
 * 教室作業卡片（Issue #131 P8）
 *
 * 只渲染「目前章節自己的作業」，含完整可互動的繳交表單。
 * 對應 specs/ui/教室作業區塊.md 的「作業卡片」與「繳交表單」兩節：
 *   - 標題 / 作業說明 / 截止時間 / 狀態徽章（未繳交・待批改・通過・未通過）/ 評語
 *   - 未繳交且已過截止時間加「已逾期」警示（仍可繳交）
 *   - 按鈕文案未繳交為「繳交」、已繳交為「重新繳交」；狀態為通過時整張表單不再渲染
 *   - 線性觀看鎖定（伺服器端判定）與管理員預覽模式：表單與按鈕全部停用
 *   - 討論串（第二波，DEC-W2-19）：見 thread.php；管理員預覽模式不輸出
 *
 * 卡片 HTML 只在這裡產生一份，vanilla TS（inc/assets/src/events/assignments）
 * 只更新狀態欄位與附件連結，不在 TS 複製一份卡片模板。
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'assignment'    => [],
	'submission'    => null,
	'status'        => 'none',
	'status_label'  => '',
	'chapter_id'    => 0,
	'chapter_title' => '',
	'is_locked'     => false,
	'is_overdue'    => false,
	'lock_message'  => '',
	'is_readonly'   => false,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'assignment'   => $assignment,
	'submission'   => $submission,
	'status'       => $card_status,
	'status_label' => $card_status_label,
	'is_locked'    => $is_locked,
	'is_overdue'   => $is_overdue,
	'lock_message' => $lock_message,
	'is_readonly'  => $is_readonly,
] = $args;

if ( ! is_array( $assignment ) ) {
	return;
}

$assignment_id = (int) ( $assignment['id'] ?? 0 );
if ( ! $assignment_id ) {
	return;
}

$card_status = (string) $card_status;
$submission  = is_array( $submission ) ? $submission : null;

$allow_text = 'yes' === (string) ( $assignment['allow_text'] ?? 'no' );
$allow_file = 'yes' === (string) ( $assignment['allow_file'] ?? 'no' );
$max_files  = max( 1, (int) ( $assignment['max_files'] ?? 1 ) );
$due_at     = (int) ( $assignment['due_at'] ?? 0 );

$is_disabled = (bool) $is_locked || (bool) $is_readonly;
// 狀態為通過時整張繳交表單（含重新繳交按鈕）都不渲染
$show_form = 'pass' !== $card_status;

// 狀態 → 徽章樣式
$badge_class_map = [
	'none'    => 'pc-badge-ghost',
	'pending' => 'pc-badge-accent',
	'pass'    => 'pc-badge-success',
	'fail'    => 'pc-badge-error',
];
$badge_class     = $badge_class_map[ $card_status ] ?? 'pc-badge-ghost';

$text_body       = null === $submission ? '' : (string) ( $submission['text_body'] ?? '' );
$feedback        = null === $submission ? '' : (string) ( $submission['feedback'] ?? '' );
$attachment_refs = [];
if ( null !== $submission && is_array( $submission['attachments'] ?? null ) ) {
	$attachment_refs = $submission['attachments'];
}

$rest_nonce = wp_create_nonce( 'wp_rest' );

// 討論串綁在繳交上：永遠輸出 data-submission-id，尚未繳交為空字串（DEC-W2-19）
$submission_id = null === $submission ? 0 : (int) ( $submission['id'] ?? 0 );

printf(
	/*html*/'<div class="pc-assignment-card border border-solid rounded-box p-4 mb-4" style="border-color:rgba(0,0,0,0.1)" data-assignment-id="%1$s" data-submission-id="%8$s" data-status="%2$s" data-locked="%3$s" data-readonly="%4$s" data-allow-text="%5$s" data-allow-file="%6$s" data-max-files="%7$s">',
	esc_attr( (string) $assignment_id ),
	esc_attr( $card_status ),
	esc_attr( $is_locked ? 'true' : 'false' ),
	esc_attr( $is_readonly ? 'true' : 'false' ),
	esc_attr( $allow_text ? 'yes' : 'no' ),
	esc_attr( $allow_file ? 'yes' : 'no' ),
	esc_attr( (string) $max_files ),
	esc_attr( $submission_id > 0 ? (string) $submission_id : '' )
);

// ── 標題與狀態徽章 ─────────────────────────────
echo /*html*/'<div class="pc-assignment-card__header flex flex-wrap items-center gap-x-2 mb-2">';
printf(
	/*html*/'<span class="pc-assignment-card__title font-bold">%s</span>',
	esc_html( (string) ( $assignment['title'] ?? '' ) )
);
printf(
	/*html*/'<span class="pc-assignment-card__status pc-badge pc-badge-sm %1$s">%2$s</span>',
	esc_attr( $badge_class ),
	esc_html( (string) $card_status_label )
);
echo /*html*/'</div>';

// ── 截止時間 / 已逾期 / 附件上限 ────────────────
echo /*html*/'<div class="pc-assignment-card__meta flex flex-wrap items-center gap-x-3 text-sm opacity-75 mb-2">';
if ( $due_at > 0 ) {
	printf(
		/*html*/'<span class="pc-assignment-card__due">%s</span>',
		esc_html(
			sprintf(
				/* translators: %s: 截止時間 */
				__( 'Due: %s', 'power-course' ),
				(string) wp_date( 'Y-m-d H:i', $due_at )
			)
		)
	);
}
printf(
	/*html*/'<span class="pc-assignment-card__overdue pc-badge pc-badge-sm pc-badge-error"%1$s>%2$s</span>',
	$is_overdue ? '' : ' style="display:none"',
	esc_html_x( 'Overdue', 'assignment', 'power-course' )
);
if ( $allow_file ) {
	printf(
		/*html*/'<span class="pc-assignment-card__max-files">%s</span>',
		esc_html(
			sprintf(
				/* translators: %s: 附件數量上限 */
				__( 'Max %s files', 'power-course' ),
				(string) $max_files
			)
		)
	);
}
echo /*html*/'</div>';

// ── 作業說明 ───────────────────────────────────
$instructions = (string) ( $assignment['instructions'] ?? '' );
if ( '' !== trim( $instructions ) ) {
	printf(
		/*html*/'<div class="pc-assignment-card__instructions text-sm mb-3">%s</div>',
		wp_kses_post( $instructions )
	);
}

// ── 講師評語 ───────────────────────────────────
printf(
	/*html*/'<div class="pc-assignment-card__feedback text-sm mb-3"%s>',
	'' === $feedback ? ' style="display:none"' : ''
);
printf(
	/*html*/'<span class="pc-assignment-card__feedback-label font-medium">%s</span>',
	esc_html__( 'Teacher feedback', 'power-course' )
);
printf(
	/*html*/'<span class="pc-assignment-card__feedback-text ml-2">%s</span>',
	esc_html( $feedback )
);
echo /*html*/'</div>';

// ── 已上傳附件（只顯示檔名，點擊經驗權後串流下載，DEC-09） ──
echo /*html*/'<div class="pc-assignment-card__attachments flex flex-col items-start gap-y-1 mb-3">';
foreach ( $attachment_refs as $attachment_ref ) {
	if ( ! is_array( $attachment_ref ) ) {
		continue;
	}
	$attachment_id = (int) ( $attachment_ref['attachment_id'] ?? 0 );
	if ( ! $attachment_id ) {
		continue;
	}
	printf(
		/*html*/'<a class="pc-assignment-card__attachment-link text-sm underline" data-attachment-id="%1$s" href="%2$s">%3$s</a>',
		esc_attr( (string) $attachment_id ),
		esc_url(
			add_query_arg(
				'_wpnonce',
				$rest_nonce,
				rest_url( 'power-course/assignment-attachments/' . $attachment_id . '/download' )
			)
		),
		esc_html( (string) ( $attachment_ref['file_name'] ?? '' ) )
	);
}
echo /*html*/'</div>';

// ── 繳交表單 ───────────────────────────────────
if ( $show_form ) {
	echo /*html*/'<div class="pc-assignment-card__form flex flex-col items-start gap-y-2">';

	if ( $is_locked ) {
		printf(
			/*html*/'<p class="pc-assignment-card__lock-message text-sm opacity-75 m-0">%s</p>',
			esc_html( (string) $lock_message )
		);
	}

	if ( $allow_text ) {
		printf(
			/*html*/'<textarea class="pc-assignment-card__text-input w-full p-2 rounded-box border border-solid" style="border-color:rgba(0,0,0,0.2)" rows="4" placeholder="%1$s"%2$s>%3$s</textarea>',
			esc_attr__( 'Enter your submission', 'power-course' ),
			$is_disabled ? ' disabled' : '',
			esc_textarea( $text_body )
		);
	}

	if ( $allow_file ) {
		printf(
			/*html*/'<input type="file" class="pc-assignment-card__file-input text-sm"%1$s%2$s />',
			$max_files > 1 ? ' multiple' : '',
			$is_disabled ? ' disabled' : ''
		);
	}

	echo /*html*/'<p class="pc-assignment-card__message text-sm m-0" style="display:none"></p>';

	printf(
		/*html*/'<button type="button" class="pc-assignment-card__submit-btn pc-btn pc-btn-sm pc-btn-primary text-white px-4"%1$s>%2$s</button>',
		$is_disabled ? ' disabled' : '',
		esc_html(
			null === $submission
				? _x( 'Submit', 'assignment submission', 'power-course' )
				: _x( 'Resubmit', 'assignment submission', 'power-course' )
		)
	);

	echo /*html*/'</div>';
}

// ── 討論串（第二波，DEC-W2-19） ─────────────────
// 狀態為通過時繳交表單隱藏，但討論串照常顯示；管理員預覽模式完全不輸出
if ( ! $is_readonly ) {
	Plugin::load_template(
		'assignment/thread',
		[
			'has_submission' => $submission_id > 0,
		]
	);
}

echo /*html*/'</div>';
