import { PlayIcon } from '@vidstack/react/icons'
import React, { useState, useEffect } from 'react'

// 倒數幾秒
const COUNTDOWN = 5

const Ended = ({ next_post_url }: { next_post_url: string }) => {
	const [countdown, setCountdown] = useState(COUNTDOWN)

	useEffect(() => {
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
	}, [countdown])

	if (!next_post_url) {
		return null
	}

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
				下個章節將在 {countdown} 秒後自動播放
			</div>
		</div>
	)
}

export default Ended
