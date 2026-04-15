import { __ } from '@wordpress/i18n'
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
import { useRecord } from '@/pages/admin/Courses/Edit/hooks'
import { PRODUCT_TYPE_OPTIONS } from '@/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/utils'
import SimplePriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple'
import SubscriptionPriceFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Subscription'
import StockFields from '@/pages/admin/Courses/Edit/tabs/CoursePrice/StockFields'

const { Item } = Form

const CoursePriceComponent = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps.form as FormInstance
	const course = useRecord()

	// 判斷是否為外部課程（hook 必須無條件呼叫）
	const watchIsExternal = Form.useWatch(['is_external'], form)
	const isExternal = course?.type === 'external' || watchIsExternal === true

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
	const isSubscription = !isExternal && watchProductType === 'subscription'

	return (
		<Form {...formProps}>
			<div className="grid grid-cols-3 gap-6">
				<div>
					<Heading>
						{isExternal
							? __('Display price', 'power-course')
							: __('Course pricing', 'power-course')}
					</Heading>
					{/* 外部課程隱藏商品種類選擇 */}
					{!isExternal && (
						<Item
							name={['type']}
							label={__('Course product type', 'power-course')}
							initialValue={PRODUCT_TYPE_OPTIONS[0].value}
						>
							<Select options={PRODUCT_TYPE_OPTIONS} />
						</Item>
					)}

					{isSubscription && <SubscriptionPriceFields />}

					<SimplePriceFields
						regularPriceItemProps={{
							hidden: isSubscription,
						}}
					/>

					{/* 外部課程隱藏庫存設定 */}
					{!isExternal && <StockFields />}
				</div>
				<div>
					<Heading>{__('Purchase note', 'power-course')}</Heading>
					<Item
						name={['purchase_note']}
						label={__('Purchase note', 'power-course')}
					>
						<Input.TextArea rows={6} />
					</Item>
					{/* 外部課程隱藏免費/隱藏單堂課 toggles */}
					{!isExternal && (
						<div className="grid grid-cols-2 gap-4">
							<FiSwitch
								formItemProps={{
									name: ['is_free'],
									label: __('This is a free course', 'power-course'),
								}}
							/>
							<FiSwitch
								formItemProps={{
									name: ['hide_single_course'],
									label: __('Hide single course purchase', 'power-course'),
								}}
							/>
						</div>
					)}
				</div>

				{/* 外部課程隱藏觀看期限、開課時間、課程時長 */}
				{!isExternal && (
					<div className="min-h-[12rem] mb-12">
						<Heading>{__('Watch time limit', 'power-course')}</Heading>

						<div className="flex flex-col gap-y-6">
							<DatePicker
								formItemProps={{
									name: ['course_schedule'],
									label: __('Course start time', 'power-course'),
									className: 'mb-0',
								}}
							/>

							<div>
								<p className="mb-2">
									{__('Course duration', 'power-course')}
								</p>
								<Space.Compact block>
									<Item name={['course_hour']} noStyle>
										<InputNumber
											className="w-1/2"
											min={0}
											addonAfter={__('Hour', 'power-course')}
										/>
									</Item>
									<Item name={['course_minute']} noStyle>
										<InputNumber
											className="w-1/2"
											min={0}
											addonAfter={__('Minute', 'power-course')}
										/>
									</Item>
								</Space.Compact>
							</div>

							<WatchLimit />
						</div>
					</div>
				)}
			</div>
		</Form>
	)
}

export const CoursePrice = memo(CoursePriceComponent)
