import { EyeOutlined } from '@ant-design/icons'
import { Image } from 'antd'
import { renderHTML } from 'antd-toolkit'

import defaultImage from '@/assets/images/defaultImage.jpg'

type TBaseRecord = {
	id: string
	name: string
}

type TProductNameProps<T extends TBaseRecord> = {
	record: T
	onClick?: () => void
	hideImage?: boolean
	label?: string
}

export const ProductName = <T extends TBaseRecord>({
	record,
	onClick,
	hideImage = false,
	label,
}: TProductNameProps<T>) => {
	// @ts-expect-error
	const { id = '', sku = '', name = '', images = [] } = record
	const image_url = hideImage ? undefined : images?.[0]?.url || defaultImage

	return (
		<>
			<div className="flex">
				{!hideImage && (
					<div className="mr-4">
						<Image
							className="rounded-md object-cover"
							preview={{
								mask: <EyeOutlined />,
								maskClassName: 'rounded-md',
								forceRender: true,
							}}
							width={72}
							height={40}
							src={image_url || defaultImage}
							fallback={defaultImage}
						/>
					</div>
				)}
				<div className="flex-1">
					<p
						className="mb-1 text-primary hover:text-primary/70 cursor-pointer"
						onClick={onClick ? onClick : undefined}
					>
						{renderHTML(label ? label : name)}
					</p>
					<div className="flex text-[0.675rem] text-gray-400">
						<span className="pr-3">{`ID: ${id}`}</span>
						{sku && <span className="pr-3">{`SKU: ${sku}`}</span>}
					</div>
				</div>
			</div>
		</>
	)
}
