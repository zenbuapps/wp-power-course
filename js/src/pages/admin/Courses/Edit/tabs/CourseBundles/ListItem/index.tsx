import { useDelete } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Tag, Typography } from 'antd'
import { ProductName } from 'antd-toolkit/wp'
import React, { memo } from 'react'

import { DuplicateButton, PopconfirmDelete } from '@/components/general'
import { ProductPrice, ProductTotalSales } from '@/components/product'
import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { getPostStatus, productTypes } from '@/utils'

const { Text } = Typography

const ListItem = ({
	record,
	index,
	setSelectedProduct,
	selectedProduct,
}: {
	record: TBundleProductRecord
	index: number
	setSelectedProduct: React.Dispatch<
		React.SetStateAction<TBundleProductRecord | null>
	>
	selectedProduct: TBundleProductRecord | null
}) => {
	const { id, name, status, type } = record
	const tag = productTypes.find((productType) => productType.value === type)
	const { mutate: deleteProduct } = useDelete()

	return (
		<div
			className="grid gap-x-2 w-full pl-2"
			style={{
				// 此元件獨有的 arbitrary 值改為 inline，避免依賴 Powerhouse tailwind build
				// 掃描後才生成 css（power-course 本身不出 css）。標準工具類仍走 className。
				gridTemplateColumns: 'minmax(0,1fr) 4rem 3rem 2rem 6rem 4rem',
				borderRadius: '0.25rem',
				backgroundColor:
					id === selectedProduct?.id ? '#e6f4ff' : 'rgba(0,0,0,0.02)',
			}}
		>
			{/* <div className="self-center">
				<HolderOutlined
					className="cursor-grab hover:bg-gray-200 rounded-lg py-3 px-0.5"
					{...listeners}
				/>
			</div> */}

			<div className="self-center min-w-0">
				<ProductName
					record={record as any}
					onClick={() => setSelectedProduct(record)}
					hideImage={false}
					renderTitle={
						// 以 Typography.Text 自渲染標題，限制單行 + ellipsis，hover 顯示完整名稱
						// 取代 antd-toolkit 內建標題（其 truncate 僅作用於子元素，純文字節點無效）
						<Text
							className="text-primary text-base cursor-pointer"
							ellipsis={{
								tooltip: (
									<>
										{name} <span className="text-gray-400 text-xs">#{id}</span>
									</>
								),
							}}
						>
							{name}{' '}
							<span className="text-gray-400 text-xs font-light">#{id}</span>
						</Text>
					}
				/>
			</div>

			<div className="self-center">
				<Tag bordered={false} color={tag?.color} className="m-0">
					{tag?.label}
				</Tag>
			</div>

			<div className="self-center">
				<Tag color={getPostStatus(status)?.color}>
					{getPostStatus(status)?.label}
				</Tag>
			</div>

			<div className="self-center place-self-center">
				<ProductTotalSales record={record} />
			</div>

			<div className="self-center whitespace-normal">
				<ProductPrice record={record} />
			</div>

			<div className="self-center flex gap-x-2">
				<DuplicateButton
					id={id}
					invalidateProps={{ resource: 'bundle_products' }}
					tooltipProps={{ title: __('Duplicate bundle', 'power-course') }}
				/>
				<PopconfirmDelete
					type="icon"
					tooltipProps={{ title: __('Delete', 'power-course') }}
					popconfirmProps={{
						title: __(
							'Are you sure you want to delete this bundle?',
							'power-course'
						),
						onConfirm: () =>
							deleteProduct({
								dataProviderName: 'power-course',
								resource: 'bundle_products',
								id,
								mutationMode: 'optimistic',
							}),
					}}
				/>
			</div>
		</div>
	)
}

export default memo(ListItem)
