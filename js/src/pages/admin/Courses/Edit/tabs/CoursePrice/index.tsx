import {
	Form,
	Input,
	Select,
	InputNumber,
	Space,
	FormProps,
	FormInstance,
} from 'antd'
import React, { useEffect, memo } from 'react'

import { FiSwitch, DatePicker, WatchLimit } from '@/components/formItem'
import { Heading } from '@/components/general'
import { PRODUCT_TYPE_OPTIONS } from '@/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/utils'
import SimplePriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple'
import SubscriptionPriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Subscription'
import StockFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/StockFields'

const { Item } = Form

const CoursePriceComponent = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps.form as FormInstance
	const watchIsFree = Form.useWatch(['is_free'], form) === 'yes'

	useEffect(() => {
		if (watchIsFree) {
			form.setFieldsValue({
				regular_price: 0,
				sale_price: 0,
				sale_date_range: undefined,
			})
		}
	}, [watchIsFree])

	const watchProductType = Form.useWatch(['type'], form)
	const isSubscription = watchProductType === 'subscription'

	return (
		<Form {...formProps}>
			<div className="grid grid-cols-3 gap-6">
				<div>
					<Heading>課程訂價</Heading>
					<Item
						name={['type']}
						label="課程商品種類"
						initialValue={PRODUCT_TYPE_OPTIONS[0].value}
					>
						<Select options={PRODUCT_TYPE_OPTIONS} />
					</Item>

					{isSubscription && <SubscriptionPriceFields />}

					<SimplePriceFields
						regularPriceItemProps={{
							hidden: isSubscription,
						}}
					/>

					<StockFields />
				</div>
				<div>
					<Heading>購買備註</Heading>
					<Item name={['purchase_note']} label="購買備註">
						<Input.TextArea rows={6} />
					</Item>
					<div className="grid grid-cols-2 gap-4">
						<FiSwitch
							formItemProps={{
								name: ['is_free'],
								label: '這是免費課程',
							}}
						/>
						<FiSwitch
							formItemProps={{
								name: ['hide_single_course'],
								label: '隱藏購買單堂課',
							}}
						/>
					</div>
				</div>

				<div className="min-h-[12rem] mb-12">
					<Heading>觀看期限</Heading>

					<div className="flex flex-col gap-y-6">
						<DatePicker
							formItemProps={{
								name: ['course_schedule'],
								label: '開課時間',
								className: 'mb-0',
							}}
						/>

						<div>
							<p className="mb-2">課程時長</p>
							<Space.Compact block>
								<Item name={['course_hour']} noStyle>
									<InputNumber className="w-1/2" min={0} addonAfter="時" />
								</Item>
								<Item name={['course_minute']} noStyle>
									<InputNumber className="w-1/2" min={0} addonAfter="分" />
								</Item>
							</Space.Compact>
						</div>

						<WatchLimit />
					</div>
				</div>
			</div>
		</Form>
	)
}

export const CoursePrice = memo(CoursePriceComponent)
