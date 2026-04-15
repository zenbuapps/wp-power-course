import { __, sprintf } from '@wordpress/i18n'
import { FC } from 'react'

export const SecondToStr: FC<{
	className?: string
	second: number
}> = ({ className = 'text-gray-400 text-xs', second }) => {
	if (!second) {
		return (
			<div className={className}>
				{sprintf(
					// translators: 三個 -- 分別為時、分、秒的預留位置
					__('%1$s h %2$s m %3$s s', 'power-course'),
					'--',
					'--',
					'--'
				)}
			</div>
		)
	}

	const hours = Math.floor(second / 60 / 60)
	const minutes = Math.floor((second / 60) % 60)
	const seconds = Math.floor(second % 60)

	return (
		<div className={className}>
			{sprintf(
				// translators: 1: 時, 2: 分, 3: 秒（均為兩位數字串）
				__('%1$s h %2$s m %3$s s', 'power-course'),
				hours > 99 ? `${hours}` : hours.toString().padStart(2, '0'),
				minutes.toString().padStart(2, '0'),
				seconds.toString().padStart(2, '0')
			)}
		</div>
	)
}
