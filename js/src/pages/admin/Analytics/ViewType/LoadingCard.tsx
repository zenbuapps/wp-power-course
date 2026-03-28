import { Card } from 'antd'
import { nanoid } from 'nanoid'
import React, { useMemo } from 'react'

const LoadingCard = ({ card }: { card: any }) => {
	// 隨機產生 10 個 2~15 的 array
	const randomArray = useMemo(() => {
		return Array.from({ length: 12 }, () => Math.floor(Math.random() * 8) + 2)
	}, [])

	return (
		<Card
			title={card?.title}
			extra={
				<span className="text-sm text-gray-400 flex items-center">
					共
					<span className="bg-gray-200 inline-block w-10 h-4 rounded-md mx-2 animate-pulse"></span>
					{card.unit}
				</span>
			}
			variant="borderless"
		>
			<div className="aspect-video grid grid-cols-12 gap-x-4 items-end animate-pulse">
				{randomArray.map((n) => (
					<div
						key={nanoid(4)}
						className="w-full bg-gray-200"
						style={{ height: `${n * 10}%` }}
					></div>
				))}
			</div>
		</Card>
	)
}

export default LoadingCard
