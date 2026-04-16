import { useDelete } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Tag } from 'antd'
import { ProductName } from 'antd-toolkit/wp'
import React, { memo } from 'react'

import { DuplicateButton, PopconfirmDelete } from '@/components/general'
import {
	ProductPrice,
	ProductTotalSales,
	ProductBoundCourses,
} from '@/components/product'
import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { getPostStatus, productTypes } from '@/utils'

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
	const { id, status, type } = record
	const tag = productTypes.find((productType) => productType.value === type)
	const { mutate: deleteProduct } = useDelete()

	return (
		<div
			className={`grid gap-x-2 grid-cols-[1fr_10rem_4rem_3rem_2rem_6rem_4rem] w-full pl-2 rounded-[0.25rem] ${id === selectedProduct?.id ? 'bg-[#e6f4ff]' : 'bg-[rgba(0,0,0,0.02)]'}`}
		>
			{/* <div className="self-center">
				<HolderOutlined
					className="cursor-grab hover:bg-gray-200 rounded-lg py-3 px-0.5"
					{...listeners}
				/>
			</div> */}

			<div className="self-center">
				<ProductName
					record={record as any}
					onClick={() => setSelectedProduct(record)}
					hideImage={false}
				/>
			</div>

			<div className="self-center justify-self-end">
				<ProductBoundCourses
					record={record}
					className="grid-cols-[2rem_6rem]"
					hideName
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
