import { useParsed } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
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
				label={__('Linked Course', 'power-course')}
				initialValue={[courseId]}
				hidden
			/>
			<Item
				name={INCLUDED_PRODUCT_IDS_FIELD_NAME}
				label={__('Linked Product', 'power-course')}
				initialValue={[]}
				hidden
			/>

			{isSubscription && <SubscriptionPriceFields />}

			<SimplePriceFields
				regularPriceItemProps={{
					hidden: true,
					label: __('Bundle Regular Price', 'power-course'),
				}}
				salePriceItemProps={{
					label: __('Bundle Sale Price', 'power-course'),
					help: (
						<div className="mb-4">
							<div className="grid grid-cols-2 gap-x-4">
								<div>{__('Bundle Original Regular Price', 'power-course')}</div>
								<div className="text-right pr-0">{bundleRegularPrice}</div>
								<div>{__('Bundle Original Sale Price', 'power-course')}</div>
								<div className="text-right pr-0">{bundleSalePrice}</div>
							</div>
							{watchSalePrice > watchRegularPrice && (
								<p className="text-red-500 m-0">
									{__('Sale price exceeds regular price', 'power-course')}
								</p>
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
