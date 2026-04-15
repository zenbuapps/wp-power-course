import { useWindowSize } from '@uidotdev/usehooks'
import { renderHTML } from 'antd-toolkit'
import { round } from 'lodash-es'
import React, { memo, useState, useEffect } from 'react'

const baseSize = 1.5

export const WaterMarkComponent = ({
	interval: _interval,
	qty,
	text,
	style,
	isPlaying,
}: {
	interval: number
	qty: number
	text: string
	style: React.CSSProperties
	isPlaying: boolean
}) => {
	const [positions, setPositions] = useState<
		{
			top: string
			bottom: string
			left: string
			right: string
		}[]
	>([]) // 隨機位置
	const { width } = useWindowSize()
	const fontSize = Math.max(round(baseSize * ((width || 1980) / 1980), 2), 0.75) // 至少 0.75rem
	const interval = _interval || 10

	useEffect(() => {
		// 初始化時，隨機出 {qty} 個 位置 style 屬性
		// 每 {interval} 秒更新一次
		const timer = setInterval(() => {
			const positionArr = new Array(qty || 0).fill(0).map((_, index) => {
				const isTop = Math.random() > 0.5
				const isLeft = Math.random() > 0.5
				const top = isTop ? `${getRandom(0, 50)}%` : 'unset'
				const bottom = isTop ? 'unset' : `${getRandom(0, 50)}%`
				const left = isLeft ? `${getRandom(0, 50)}%` : 'unset'
				const right = isLeft ? 'unset' : `${getRandom(0, 50)}%`
				return {
					top,
					bottom,
					left,
					right,
				}
			})
			setPositions(positionArr)
		}, interval * 1000)

		return () => {
			clearInterval(timer)
		}
	}, [])

	if (!text || !qty) {
		return null
	}

	return (
		<>
			{new Array(qty).fill(0).map((_, index) => (
				<div
					key={index}
					style={{
						position: 'absolute',
						...positions?.[index],
						color: style?.color || 'rgba(255, 255, 255, 0.5)',
						fontSize: `${fontSize}rem`,
						fontWeight: 'bold',
						pointerEvents: 'none',
					}}
				>
					{renderHTML(text)}
				</div>
			))}
		</>
	)
}

export const WaterMark = memo(WaterMarkComponent)

function getRandom(min = 20, max = 100) {
	return Math.floor(Math.random() * (max - min + 1)) + min
}
