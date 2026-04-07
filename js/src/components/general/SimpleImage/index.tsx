import { cn } from 'antd-toolkit'
import { FC, HTMLAttributes } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'

type SimpleImageProps = {
	src: string
	className?: string
	ratio?: string
	loadingClassName?: string
} & HTMLAttributes<HTMLDivElement>

export const SimpleImage: FC<SimpleImageProps> = ({
	children,
	className = 'w-full',
	ratio = 'aspect-video',
	src = defaultImage,
	loadingClassName = 'text-xl text-gray-400 font-bold',
	...rest
}) => {
	return (
		<div className={cn('relative', className, ratio)} {...rest}>
			<img
				alt=""
				src={src}
				loading="lazy"
				className={`relative z-20 w-full ${ratio} object-cover ${children ? 'opacity-50' : ''}`}
			/>
			<div
				className={`absolute z-10 top-0 left-0 w-full ${ratio} bg-gray-200 animate-pulse flex items-center justify-center ${loadingClassName}`}
			>
				LOADING...
			</div>
			{children && (
				<div
					className={`absolute z-30 top-0 left-0 w-full ${ratio} flex items-center justify-center`}
				>
					{children}
				</div>
			)}
		</div>
	)
}
