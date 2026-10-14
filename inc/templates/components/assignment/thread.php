<?php
/**
 * 教室作業卡片的討論串（Issue #131 第二波 W4，DEC-W2-19）
 *
 * 對應 specs/ui/教室作業區塊.md「討論串（第二波）」與
 * features/assignment/{查詢作業討論串,發送作業討論訊息}.feature：
 *   - 切換鈕「與老師討論」、訊息清單、輸入列，以及單則訊息節點的 <template>
 *   - 尚未繳交時整塊 hidden；頁內首次繳交成功後由 submit.ts 移除 hidden
 *   - 不在 PHP 預先渲染訊息：展開才由 thread.ts 讀取（讀取 = 標老師訊息已讀，DEC-W2-04）
 *   - 線性觀看鎖定不影響討論串（D29），所以這裡不吃卡片的 disabled 狀態
 *
 * 管理員預覽模式不載入本模板（由 card.php 判斷）。
 * 訊息節點只來自下方 <template>，TS 端 clone 後以 textContent 填值，不拼 HTML（DEC-15、R-W2-09）。
 *
 * 注意：會切換 hidden 屬性的元素不可帶 display 類 utility（flex / grid…）——
 * 前台 CSS 以 #tw 提高權重，會蓋過瀏覽器對 [hidden] 的 display:none。
 */

$default_args = [
	'has_submission' => false,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

$has_submission = (bool) $args['has_submission'];

printf(
	/*html*/'<div class="pc-assignment-card__thread mt-3"%s>',
	$has_submission ? '' : ' hidden'
);

printf(
	/*html*/'<button type="button" class="pc-assignment-card__thread-toggle pc-btn pc-btn-sm pc-btn-outline border-solid px-4" aria-expanded="false">%s</button>',
	esc_html__( 'Discuss with teacher', 'power-course' )
);

// 展開後的內容：狀態提示（載入中 / 尚無訊息 / 錯誤）、訊息清單、輸入列
echo /*html*/'<div class="pc-assignment-card__thread-panel mt-3" hidden>';

echo /*html*/'<p class="pc-assignment-card__thread-status text-sm opacity-75 m-0" hidden></p>';

echo /*html*/'<div class="pc-assignment-card__thread-list overflow-y-auto" style="max-height:320px"></div>';

echo /*html*/'<div class="pc-assignment-card__thread-form flex items-center gap-x-2 mt-2">';
printf(
	/*html*/'<input type="text" class="pc-assignment-card__thread-input pc-input pc-input-sm pc-input-bordered flex-1 min-w-0" maxlength="10000" placeholder="%1$s" aria-label="%1$s" />',
	esc_attr__( 'Type a message...', 'power-course' )
);
printf(
	/*html*/'<button type="button" class="pc-assignment-card__thread-send pc-btn pc-btn-sm pc-btn-primary text-white px-4">%s</button>',
	esc_html__( 'Send', 'power-course' )
);
echo /*html*/'</div>';

echo /*html*/'</div>';

// 單則訊息節點：寄件者與時間、本文、已讀（只有自己的訊息被老師讀過才保留，其餘由 TS 移除）
echo /*html*/'<template class="pc-assignment-card__thread-template">';
echo /*html*/'<div class="pc-assignment-card__thread-message pc-chat">';
echo /*html*/'<div class="pc-chat-header text-xs opacity-75">';
echo /*html*/'<span class="pc-assignment-card__thread-sender font-medium"></span> ';
echo /*html*/'<time class="pc-assignment-card__thread-time"></time>';
echo /*html*/'</div>';
echo /*html*/'<div class="pc-assignment-card__thread-body pc-chat-bubble text-sm whitespace-pre-wrap" style="word-break:break-word"></div>';
printf(
	/*html*/'<div class="pc-assignment-card__thread-read pc-chat-footer text-xs opacity-75">%s</div>',
	esc_html_x( 'Read', 'assignment message status', 'power-course' )
);
echo /*html*/'</div>';
echo /*html*/'</template>';

echo /*html*/'</div>';
