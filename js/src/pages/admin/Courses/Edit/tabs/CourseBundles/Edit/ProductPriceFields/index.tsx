import { useParsed } from '@refinedev/core'
import { Form } from 'antd'
import React from 'react'

import SimplePriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple'
import SubscriptionPriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Subscription'
import StockFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/StockFields'

import { INCLUDED_PRODUCT_IDS_FIELD_NAME } from '../utils'

const { Item } = Form

const ProductPriceFields = ({
	bundlePrices,
}: {
	bundlePrices: { regular_price: React.ReactNode; sale_price: React.ReactNode }
}) => {
	const bundleProductForm = Form.useFormInstance()
	const watchProductType = Form.useWatch(['type'], bundleProductForm)
	const isSubscription = 'subscription' === watchProductType

	const watchRegularPrice = Number(
		Form.useWatch(['regular_price'], bundleProductForm)
	)
	const watchSalePrice = Number(
		Form.useWatch(['sale_price'], bundleProductForm)
	)

	const { id: courseId } = useParsed()

	const { regular_price: bundleRegularPrice, sale_price: bundleSalePrice } =
		bundlePrices

	return (
		<>
			<Item
				name="bind_course_ids"
				label="綁定課程"
				initialValue={[courseId]}
				hidden
			/>
			<Item
				name={INCLUDED_PRODUCT_IDS_FIELD_NAME}
				label="連接商品"
				initialValue={[]}
				hidden
			/>

			{isSubscription && <SubscriptionPriceFields />}

			<SimplePriceFields
				regularPriceItemProps={{
					hidden: true,
					label: '此銷售組合原價',
				}}
				salePriceItemProps={{
					label: '方案折扣價',
					help: (
						<div className="mb-4">
							<div className="grid grid-cols-2 gap-x-4">
								<div>此銷售組合原訂原價</div>
								<div className="text-right pr-0">{bundleRegularPrice}</div>
								<div>此銷售組合原訂折扣價</div>
								<div className="text-right pr-0">{bundleSalePrice}</div>
							</div>
							{watchSalePrice > watchRegularPrice && (
								<p className="text-red-500 m-0">折扣價超過原價</p>
							)}
						</div>
					),
				}}
			/>

			<StockFields />
		</>
	)
}

export default ProductPriceFields
