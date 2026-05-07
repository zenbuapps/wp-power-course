import { PlayIcon } from '@vidstack/react/icons'
import { __, _n, sprintf } from '@wordpress/i18n'
import React, { useEffect, useRef, useState } from 'react'

// 倒數幾秒
const COUNTDOWN = 5

type TEndedProps = {
	next_post_url: string
	onReplay: () => void
	/**
	 * 章節是否已完成（finished_at 已寫入）。
	 * true → 重看模式：不倒數、不自動跳下一章，改為手動「下一章 / 重看本章」雙按鈕。
	 * false → 首次完成：維持原本 5 秒倒數自動跳下一章流程。
	 */
	isFinished?: boolean
}

/**
 * Ended 倒數遮罩元件
 *
 * 影片播放至結尾時顯示全屏遮罩：
 * - 首次完成（isFinished=false）：中央圓形倒數動畫 + 倒數文字 + 「重看本章」按鈕；
 *   倒數歸零後自動跳轉至 `next_post_url`。
 * - 重看模式（isFinished=true）：保留中央圓形 PlayIcon 按鈕（點擊跳下一章，無倒數
 *   環動畫）+ 完成提示文字 + 「重看本章」按鈕，關閉倒數與自動跳轉，避免已完成
 *   章節因 DB 內 last_position_seconds 接近結尾而被初始 seek 推到末端後立刻觸發
 *   ended → 再次被自動跳下一章的循環。
 *
 * 注意：原本 Q1=C 雙按鈕（取消 + 重看）設計已因 VidStack ended 狀態的
 * 多個 BUG（播放按鈕消失、拖拉進度條觸發 progress API 把進度條 seek 回片尾）
 * 於 2026-04-20 人工測試後調整為 Q1=B 僅保留「重看本章」。重看模式下的 PlayIcon
 * 與「重看本章」都是明確的「手動操作」路徑，不會觸發原本 Q1=C 所遇到的
 * 自動 seek 回片尾問題。
 */
const Ended = ({
	next_post_url,
	onReplay,
	isFinished = false,
}: TEndedProps) => {
	const [countdown, setCountdown] = useState(COUNTDOWN)

	/**
	 * Q9=A 決策：守衛 window.location.href
	 *
	 * 使用 ref 同步寫入避免 setInterval / useEffect 競態。
	 * 當用戶按下「重看本章」後，即使殘留的 interval tick 仍執行，
	 * 跳轉路徑也會因 isCancelledRef.current === true 而 early return。
	 */
	const isCancelledRef = useRef<boolean>(false)

	/**
	 * Effect A：倒數計時器
	 *
	 * 只跑一次：掛載後啟動 setInterval 每秒遞減 countdown，卸載時 clearInterval。
	 * 拆開為獨立 effect 避免依賴 countdown 導致每秒 teardown/setup interval。
	 *
	 * 重看模式（isFinished=true）不啟動倒數。
	 */
	useEffect(() => {
		if (isFinished) return
		if (isCancelledRef.current) return

		const interval = setInterval(() => {
			setCountdown((c) => (c > 0 ? c - 1 : 0))
		}, 1000)

		return () => clearInterval(interval)
	}, [isFinished])

	/**
	 * Effect B：倒數歸零後跳轉至下一章
	 *
	 * 僅在 countdown 變為 0 時觸發一次跳轉，並再次檢查 isCancelledRef 守衛
	 * 避免使用者在最後一刻按下按鈕後仍被跳轉。
	 *
	 * 重看模式（isFinished=true）跳過自動跳轉。
	 */
	useEffect(() => {
		if (isFinished) return
		if (countdown === 0 && next_post_url && !isCancelledRef.current) {
			window.location.href = next_post_url
		}
	}, [countdown, next_post_url, isFinished])

	if (!next_post_url) {
		return null
	}

	// 重看模式：關閉倒數與自動跳轉，改顯示「下一章 / 重看本章」手動雙按鈕
	if (isFinished) {
		return (
			<div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
				<div
					className="w-12 h-12 p-2 bg-white/70 rounded-full mb-8 relative cursor-pointer"
					onClick={(e) => {
						e.stopPropagation()
						window.location.href = next_post_url
					}}
				>
					<PlayIcon />
				</div>
				<div className="text-white text-base font-thin mb-6">
					{__('You have finished this chapter.', 'power-course')}
				</div>
				<button
					type="button"
					className="pc-btn pc-btn-primary pc-btn-sm px-0 lg:px-4 w-full lg:w-auto text-xs sm:text-base pc-btn-outline border-solid"
					onClick={(e) => {
						e.stopPropagation()
						onReplay()
					}}
				>
					{__('Replay chapter', 'power-course')}
				</button>
			</div>
		)
	}

	// 首次完成：維持原本倒數自動跳下一章流程
	return (
		<div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
			<div
				className="w-12 h-12 p-2 bg-white/70 rounded-full mb-8 relative cursor-pointer"
				onClick={(e) => {
					e.stopPropagation()
					if (isCancelledRef.current) return
					window.location.href = next_post_url
				}}
			>
				<PlayIcon />
				<div
					className="progress-circle absolute top-0 left-0"
					style={{
						top: '-0.5rem',
						left: '-0.5rem',
						width: '4rem',
						height: '4rem',
					}}
				>
					<svg className="w-full h-full">
						<circle
							cx="32"
							cy="32"
							r="28"
							fill="none"
							stroke="#ffffff"
							strokeWidth="4"
							strokeLinecap="butt"
							strokeDasharray="176"
							strokeDashoffset="176"
							transform="rotate(-90,32,32)"
							style={{
								animation: `circle-progress ${COUNTDOWN}s linear forwards`,
							}}
						></circle>
					</svg>
				</div>
			</div>
			<div className="text-white text-base font-thin mb-6">
				{sprintf(
					// translators: %d: 倒數剩餘秒數
					_n(
						'Next chapter will play in %d second',
						'Next chapter will play in %d seconds',
						countdown,
						'power-course'
					),
					countdown
				)}
			</div>
			<button
				type="button"
				className="pc-btn pc-btn-primary pc-btn-sm px-0 lg:px-4 w-full lg:w-auto text-xs sm:text-base pc-btn-outline border-solid"
				onClick={(e) => {
					e.stopPropagation()
					if (isCancelledRef.current) return
					isCancelledRef.current = true
					onReplay()
				}}
			>
				{__('Replay chapter', 'power-course')}
			</button>
		</div>
	)
}

export default Ended
