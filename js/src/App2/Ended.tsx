import { PlayIcon } from '@vidstack/react/icons'
import { __, sprintf } from '@wordpress/i18n'
import React, { useState, useEffect } from 'react'

// 倒數幾秒
const COUNTDOWN = 5

type TEndedProps = {
	next_post_url: string
}

const Ended = ({ next_post_url }: TEndedProps) => {
	const [countdown, setCountdown] = useState(COUNTDOWN)
	const [nextLocked, setNextLocked] = useState<boolean>(
		() => !!(window as any).pc_data?.next_chapter_locked,
	)
	const isLinearViewing = !!(window as any).pc_data?.linear_viewing

	// 監聽 pc_data.next_chapter_locked 變化（自動完成後更新）
	useEffect(() => {
		const checkLocked = () => {
			setNextLocked(!!(window as any).pc_data?.next_chapter_locked)
		}
		// 輪詢檢查（因為 pc_data 是 window 物件，非 reactive）
		const interval = setInterval(checkLocked, 500)
		return () => clearInterval(interval)
	}, [])

	// 倒數邏輯：僅在下一章未鎖定時啟動
	const shouldCountdown = !isLinearViewing || !nextLocked

	useEffect(() => {
		if (!shouldCountdown) return

		const interval = setInterval(() => {
			if (countdown > 0) {
				setCountdown(countdown - 1)
			}
		}, 1000)

		if (0 === countdown && next_post_url) {
			// 跳轉到下一個章節
			window.location.href = next_post_url
		}

		return () => clearInterval(interval)
	}, [countdown, shouldCountdown, next_post_url])

	if (!next_post_url) {
		return null
	}

	// 下一章鎖定：顯示引導提示
	if (isLinearViewing && nextLocked) {
		return (
			<div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
				<div className="text-white text-center px-4">
					<div className="text-4xl mb-4">
						<svg
							className="size-12 mx-auto"
							viewBox="0 0 24 24"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
						>
							<path
								d="M12 14.5V16.5M7 10.0288C7.47142 10 8.05259 10 8.8 10H15.2C15.9474 10 16.5286 10 17 10.0288M7 10.0288C6.41168 10.0647 5.99429 10.1455 5.63803 10.327C5.07354 10.6146 4.6146 11.0735 4.32698 11.638C4 12.2798 4 13.1198 4 14.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V14.8C20 13.1198 20 12.2798 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C18.0057 10.1455 17.5883 10.0647 17 10.0288M7 10.0288V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10.0288"
								stroke="white"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
							/>
						</svg>
					</div>
					<div className="text-base font-thin mb-2">
						{__('Complete this chapter to unlock the next one', 'power-course')}
					</div>
					<div className="text-sm opacity-70">
						{__('Click the "Mark as finished" button below', 'power-course')}
					</div>
				</div>
			</div>
		)
	}

	// 正常倒數跳轉
	return (
		<div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
			<div
				className="w-12 h-12 p-2 bg-white/70 rounded-full mb-8 relative cursor-pointer"
				onClick={() => {
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
			<div className="text-white text-base font-thin">
				{sprintf(
					/* translators: %d: 倒數秒數 */
					__('Next chapter will auto-play in %d seconds', 'power-course'),
					countdown,
				)}
			</div>
		</div>
	)
}

export default Ended
