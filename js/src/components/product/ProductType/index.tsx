import {
	StarFilled,
	StarOutlined,
	CloudOutlined,
	CloudFilled,
} from '@ant-design/icons'
import { Tag, Tooltip } from 'antd'
import React, { FC, memo } from 'react'
import { IoMdDownload } from 'react-icons/io'
import { __, sprintf } from '@wordpress/i18n'

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
				title={
					record?.featured
						? __('Featured product', 'power-course')
						: __('Not featured product', 'power-course')
				}
			>
				{record?.featured ? (
					<StarFilled className="text-yellow-400" />
				) : (
					<StarOutlined className="text-gray-400" />
				)}
			</Tooltip>

			<Tooltip
				zIndex={1000000 + 20}
				title={
					record?.virtual
						? __('Virtual product', 'power-course')
						: __('Not virtual product', 'power-course')
				}
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
					title={
						record?.downloadable
							? __('Downloadable', 'power-course')
							: __('Not downloadable', 'power-course')
					}
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
					title={sprintf(
						// translators: 1: 關聯課程 ID 列表, 2: bundle 類型標籤
						__('This product is a %2$s bundle of #%1$s', 'power-course'),
						link_course_ids,
						getBundleType(bundle_type)?.label
					)}
				>
					<Tag bordered={false} color="purple" className="m-0">
						{__('Bundle', 'power-course')}
					</Tag>
				</Tooltip>
			)}
		</div>
	)
}

export const ProductType = memo(ProductTypeComponent)
