import {
	StarFilled,
	StarOutlined,
	CloudOutlined,
	CloudFilled,
} from '@ant-design/icons'
import { Tag, Tooltip } from 'antd'
import React, { FC, memo } from 'react'
import { IoMdDownload } from 'react-icons/io'

import { TProductRecord } from '@/components/product/ProductTable/types'
import { productTypes, getBundleType } from '@/utils'

const ProductTypeComponent: FC<{
	record: TProductRecord
	hideDownloadable?: boolean
}> = ({ record, hideDownloadable = true }) => {
	const type = record?.type || ''
	const bundle_type = record?.bundle_type || ''
	const link_course_ids = record?.link_course_ids || ''
	if (!type || 'chapter' === type) return null
	const tag = productTypes.find((productType) => productType.value === type)
	return (
		<div className="flex items-center gap-2">
			<Tag bordered={false} color={tag?.color} className="m-0">
				{tag?.label}
			</Tag>
			<Tooltip
				zIndex={1000000 + 20}
				title={`${record?.featured ? '' : '非'}精選商品`}
			>
				{record?.featured ? (
					<StarFilled className="text-yellow-400" />
				) : (
					<StarOutlined className="text-gray-400" />
				)}
			</Tooltip>

			<Tooltip
				zIndex={1000000 + 20}
				title={`${record?.virtual ? '' : '非'}虛擬商品`}
			>
				{record?.virtual ? (
					<CloudFilled className="text-primary" />
				) : (
					<CloudOutlined className="text-gray-400" />
				)}
			</Tooltip>

			{!hideDownloadable && (
				<Tooltip
					zIndex={1000000 + 20}
					title={`${record?.downloadable ? '' : '不'}可下載`}
				>
					{record?.downloadable ? (
						<IoMdDownload className="text-gray-900" />
					) : (
						<IoMdDownload className="text-gray-400" />
					)}
				</Tooltip>
			)}

			{bundle_type && (
				<Tooltip
					title={`此商品為 #${link_course_ids} 的 ${getBundleType(bundle_type)?.label} 銷售方案`}
				>
					<Tag bordered={false} color="purple" className="m-0">
						銷售方案
					</Tag>
				</Tooltip>
			)}
		</div>
	)
}

export const ProductType = memo(ProductTypeComponent)
