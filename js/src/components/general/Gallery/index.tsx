import React, { useState, useEffect } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'

export const Gallery: React.FC<{
	images: string[] | false[]
	selectedImage?: string
}> = ({ images, selectedImage }) => {
	const isInclude = images?.some((i) => i === selectedImage)

	const [selected, setSelected] = useState(images[0])

	useEffect(() => {
		setSelected(images[0])
	}, [images])

	useEffect(() => {
		if (!!selectedImage && isInclude) {
			setSelected(selectedImage)
		} else {
			setSelected(images[0])
		}
	}, [selectedImage])

	const handleClick = (src: string | false) => () => {
		if (src) {
			setSelected(src)
		} else {
			setSelected(defaultImage)
		}
	}

	if (images.length === 0) {
		return (
			<img
				alt=""
				className="aspect-square w-full object-cover"
				src={defaultImage}
			/>
		)
	}

	return (
		<>
			{images.map((image, i) => (
				<img
					key={`main-${image}-${i}`}
					alt=""
					className={`aspect-square w-full object-cover ${image === selected ? 'tw-block' : 'tw-hidden'}`}
					src={image || defaultImage}
				/>
			))}
			{images.length > 1 && (
				<div className="mt-2 w-full grid grid-cols-4 gap-2">
					{images.map((image, i) => (
						<img
							key={`${image}-${i}`}
							alt=""
							className={`aspect-square cursor-pointer object-cover w-full ${image === selected && 'border-2 border-blue-500 border-solid'}`}
							onClick={handleClick(image)}
							src={image || defaultImage}
							style={{ width: '-webkit-fill-available' }}
						/>
					))}
				</div>
			)}
		</>
	)
}
