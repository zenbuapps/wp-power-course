import { PlayIcon } from '@vidstack/react/icons'
import { __, _n, sprintf } from '@wordpress/i18n'
import React, { useEffect, useRef, useState } from 'react'

// 倒數幾秒
const COUNTDOWN = 5

type TEndedProps = {
	next_post_url: string
	onReplay: () => void
}

/**
 * Ended 倒數遮罩元件
 *
 * 影片播放至結尾時顯示全屏遮罩：
 * - 中央顯示圓形倒數動畫與播放圖標
 * - 下方顯示倒數剩餘秒數文字（支援單複數）
 * - 提供「重看本章」出口按鈕（單一按鈕決策 Q1=B，post-test 2026-04-20）
 * - 倒數歸零後自動跳轉至 `next_post_url`
 *
 * 注意：原本 Q1=C 雙按鈕（取消 + 重看）設計已因 VidStack ended 狀態的
 * 多個 BUG（播放按鈕消失、拖拉進度條觸發 progress API 把進度條 seek 回片尾）
 * 於 2026-04-20 人工測試後調整為 Q1=B 僅保留「重看本章」。
 */
const Ended = ({ next_post_url, onReplay }: TEndedProps) => {
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
	 */
	useEffect(() => {
		if (isCancelledRef.current) return

		const interval = setInterval(() => {
			setCountdown((c) => (c > 0 ? c - 1 : 0))
		}, 1000)

		return () => clearInterval(interval)
	}, [])

	/**
	 * Effect B：倒數歸零後跳轉至下一章
	 *
	 * 僅在 countdown 變為 0 時觸發一次跳轉，並再次檢查 isCancelledRef 守衛
	 * 避免使用者在最後一刻按下按鈕後仍被跳轉。
	 */
	useEffect(() => {
		if (countdown === 0 && next_post_url && !isCancelledRef.current) {
			window.location.href = next_post_url
		}
	}, [countdown, next_post_url])

	if (!next_post_url) {
		return null
	}

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
