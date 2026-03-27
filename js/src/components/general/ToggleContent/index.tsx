import { renderHTML } from 'antd-toolkit'
import React, { useRef, useEffect } from 'react'

export const ToggleContent: React.FC<{
	content: string
	isExpand: boolean
	setIsExpand: React.Dispatch<React.SetStateAction<boolean>>
	showReadMore: boolean
	setShowReadMore: React.Dispatch<React.SetStateAction<boolean>>
	height?: number
}> = ({
	content,
	isExpand,
	setIsExpand,
	showReadMore,
	setShowReadMore,
	height = 300,
}) => {
	const html = renderHTML(content)

	const divRef = useRef<HTMLDivElement>(null)

	useEffect(() => {
		const timeOut = setTimeout(() => {
			if (divRef) {
				const divHeight = divRef.current?.clientHeight || 0
				if (divHeight > height - 1) {
					setShowReadMore(true)
				} else {
					setShowReadMore(false)
				}
			}
		}, 0)

		return () => {
			clearTimeout(timeOut)
		}
	}, [
		divRef,
		content,
	])

	const handleExpand = () => {
		setIsExpand(true)
	}

	return (
		<>
			<div ref={divRef} className="h-full overflow-hidden relative">
				<div
					className={`${isExpand ? 'h-full' : 'max-h-[300px]'}`}
					style={
						isExpand
							? {
									height: '100%',
								}
							: {
									maxHeight: `${height}px`,
								}
					}
				>
					{html}
				</div>
				{!isExpand && showReadMore && (
					<div
						onClick={handleExpand}
						className="absolute bottom-0 text-center w-full pb-4 pt-12 bg-gradient-to-t from-white to-white/0 cursor-pointer z-50"
					></div>
				)}
			</div>
		</>
	)
}

export * from './useToggleContent'
