/* eslint-disable @typescript-eslint/ban-ts-comment */
import { __ } from '@wordpress/i18n'
import $ from 'jquery'

/**
 * 線性觀看前端互動模組
 *
 * 負責：
 * A. 鎖定章節點擊攔截（dialog）
 * B. Toast 提示（redirect 後的 ?linear_locked=1）
 * C. URL 參數清除（history.replaceState）
 */
export function linearViewing() {
	// 確保 dialog 元素存在
	ensureLockDialog()

	// 攔截鎖定章節的點擊
	$(document).on('click', 'li[data-locked="true"]', function (e) {
		e.preventDefault()
		e.stopPropagation()
		const message =
			$(this).attr('data-lock-message') ||
			__('This chapter is not yet unlocked', 'power-course')
		showLockDialog(message)
	})

	// 攔截鎖定章節內的所有連結點擊
	$(document).on('click', 'li[data-locked="true"] a', function (e) {
		e.preventDefault()
		e.stopPropagation()
	})

	// Toast 提示（URL redirect 後）
	handleLinearLockedToast()
}

/**
 * 確保 lock dialog 元素存在於 DOM 中
 */
function ensureLockDialog() {
	if ($('#pc-linear-lock-dialog').length > 0) return
	$('body').append(`
		<dialog id="pc-linear-lock-dialog" class="pc-dialog rounded-box p-6 max-w-sm" style="border:1px solid rgba(0,0,0,0.1); border-radius:0.75rem; padding:1.5rem; max-width:24rem;">
			<h3 class="text-lg font-bold mb-4">${__('Chapter not yet unlocked', 'power-course')}</h3>
			<p id="pc-linear-lock-message" class="mb-4"></p>
			<form method="dialog">
				<button class="pc-btn pc-btn-primary w-full" style="width:100%; padding:0.5rem 1rem; cursor:pointer;">${__('OK', 'power-course')}</button>
			</form>
		</dialog>
	`)
}

/**
 * 顯示鎖定提示 dialog
 */
function showLockDialog(message: string) {
	$('#pc-linear-lock-message').text(message)
	const dialog = document.getElementById(
		'pc-linear-lock-dialog',
	) as HTMLDialogElement
	dialog?.showModal()
}

/**
 * 處理 ?linear_locked=1 參數的 toast 提示
 */
function handleLinearLockedToast() {
	const params = new URLSearchParams(window.location.search)
	if (params.get('linear_locked') !== '1') return

	// 建立 toast 元素
	const toast = $(`
		<div class="pc-toast pc-toast-info" role="alert" style="position:fixed; top:1rem; left:50%; transform:translateX(-50%); z-index:9999; padding:0.75rem 1.5rem; border-radius:0.5rem; display:flex; align-items:center; gap:0.5rem; box-shadow:0 4px 12px rgba(0,0,0,0.15); background-color:#e8f4fd; color:#1a73e8; border:1px solid #90caf9;">
			<span>${__('Please complete the previous chapters before viewing this content', 'power-course')}</span>
			<button class="pc-toast-close" aria-label="${__('Close', 'power-course')}" style="background:none; border:none; cursor:pointer; font-size:1rem; opacity:0.6;">&#x2715;</button>
		</div>
	`)
	$('body').prepend(toast)
	toast.find('.pc-toast-close').on('click', () => toast.remove())

	// 5 秒自動消失
	setTimeout(() => toast.fadeOut(300, () => toast.remove()), 5000)

	// 清除 URL 參數
	const url = new URL(window.location.href)
	url.searchParams.delete('linear_locked')
	window.history.replaceState({}, '', url.toString())
}
