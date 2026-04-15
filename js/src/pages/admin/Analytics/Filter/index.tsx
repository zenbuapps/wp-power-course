import { AreaChartOutlined, LineChartOutlined } from '@ant-design/icons'
import { useSelect } from '@refinedev/antd'
import { DatePicker, Button, Select, Form, Checkbox, Tooltip, Tag } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { objToCrudFilters } from 'antd-toolkit/refine'
import dayjs from 'dayjs'
import React, { useState, useEffect } from 'react'

import { __ } from '@wordpress/i18n'

import { TProductSelectOption } from '@/components/product/ProductTable/types'
import Tags from '@/pages/admin/Analytics/Filter/Tags'
import { useRevenueContext } from '@/pages/admin/Analytics/hooks'
import { EViewType } from '@/pages/admin/Analytics/types'
import { RANGE_PRESETS, maxDateRange } from '@/pages/admin/Analytics/utils'
import { useRecord } from '@/pages/admin/Courses/Edit/hooks'
import { productTypes } from '@/utils'

const { RangePicker } = DatePicker
const { Item } = Form

const AnalyticsFilter = () => {
	const {
		viewType,
		setViewType,
		form,
		context,
		isFetching,
		setEnabled,
		initialQuery,
	} = useRevenueContext()
	const course = useRecord()

	// 需要這個 state 是因為，需要知道用戶選了哪些課程/商品(需要挑出 is_course 為 true 的)，才能在查詢時帶入
	const [selectedCourseProducts, setSelectedCourseProducts] = useState<
		TProductSelectOption[]
	>([])

	const watchProducts = Form.useWatch(['products'], form) || []

	const { selectProps: productSelectProps, query: productQuery } =
		useSelect<TProductSelectOption>({
			resource: 'products/select',
			dataProviderName: 'power-course',
			optionLabel: 'name',
			optionValue: 'id',
			onSearch: (value) => [
				{
					field: 's',
					operator: 'eq',
					value,
				},
			],
			filters:
				context === 'detail'
					? objToCrudFilters({
							p: watchProducts,
						})
					: [],
		})

	const productSelectOptions = productQuery?.data?.data || []

	const { query: bundleProductQuery } = useSelect<TProductSelectOption>({
		resource: 'products/select',
		dataProviderName: 'power-course',
		optionLabel: 'name',
		optionValue: 'id',
		filters: objToCrudFilters({
			meta_key: 'link_course_ids',
			meta_compare: 'IN',
			meta_value:
				context === 'detail'
					? [course?.id]
					: selectedCourseProducts.map((product) => product.id),
		}),
		queryOptions: {
			enabled: !!selectedCourseProducts?.length || context === 'detail',
		},
	})

	const handleSubmit = () => {
		setEnabled(true)
	}

	const watchProductIds = Form.useWatch(['products'], form) || []

	useEffect(() => {
		const selectedCourseProductOptions = (productSelectOptions?.filter(
			(option) => watchProductIds?.includes(option?.id) && option?.is_course
		) || []) as TProductSelectOption[]
		setSelectedCourseProducts(selectedCourseProductOptions)
	}, [watchProductIds?.length, productSelectOptions?.length])

	useEffect(() => {
		setEnabled(true)
	}, [])

	return (
		<Form form={form} layout="vertical">
			<div className="flex items-center gap-x-4">
				<Item
					label={__('Date range', 'power-course')}
					name={['date_range']}
					tooltip={__('Maximum selectable range is 1 year', 'power-course')}
					initialValue={
						initialQuery?.after && initialQuery?.before
							? [dayjs(initialQuery.after), dayjs(initialQuery.before)]
							: [
									dayjs()
										.add(-7, 'd')
										.startOf('day')
										.format('YYYY-MM-DDTHH:mm:ss'),
									dayjs().endOf('day').format('YYYY-MM-DDTHH:mm:ss'),
								]
					}
					rules={[
						{
							required: true,
							message: __('Please select date range', 'power-course'),
						},
					]}
					normalize={(value) => {
						if (Array.isArray(value)) {
							if (value.every((v) => dayjs.isDayjs(v))) {
								return value.map((v) => v.format('YYYY-MM-DDTHH:mm:ss'))
							}
						}
						return value
					}}
					getValueProps={(value) => {
						if (Array.isArray(value)) {
							if (value.every((v) => typeof v === 'string')) {
								return {
									value: value.map((v) => dayjs(v)),
								}
							}
						}
						return {
							value,
						}
					}}
				>
					<RangePicker
						presets={RANGE_PRESETS}
						disabledDate={maxDateRange}
						placeholder={[
							__('Start date', 'power-course'),
							__('End date', 'power-course'),
						]}
						allowClear={false}
						className="w-[16rem]"
					/>
				</Item>

				<Item
					name={['products']}
					className="w-full"
					label={__('View specific courses/products', 'power-course')}
					initialValue={initialQuery?.product_includes}
					hidden={context === 'detail'}
				>
					<Select
						{...defaultSelectProps}
						{...productSelectProps}
						placeholder={__(
							'Multiple selection, searchable by keyword',
							'power-course'
						)}
						optionRender={({ value, label }) => {
							const option = productSelectOptions.find(
								(productOption) => productOption?.id === value
							)
							const productType = productTypes.find(
								(pt) => pt?.value === option?.type
							)
							return (
								<span>
									<span className="text-gray-400 text-xs">#{value}</span>{' '}
									{label}{' '}
									<Tag color={productType?.color}>{productType?.label}</Tag>
									{option?.is_course && (
										<Tag color="gold">{__('Course', 'power-course')}</Tag>
									)}
								</span>
							)
						}}
					/>
				</Item>

				<Item
					name={['bundle_products']}
					className="w-full"
					label={__('View bundles', 'power-course')}
					hidden
				/>
				<Item
					name={['interval']}
					initialValue={'day'}
					label={__('Time interval', 'power-course')}
				>
					<Select
						className="w-24"
						options={[
							{
								label: __('By day', 'power-course'),
								value: 'day',
							},
							{
								label: __('By week', 'power-course'),
								value: 'week',
							},
							{
								label: __('By month', 'power-course'),
								value: 'month',
							},
							{
								label: __('By quarter', 'power-course'),
								value: 'quarter',
							},
						]}
					/>
				</Item>
				<Item label=" ">
					<Button type="primary" onClick={handleSubmit} loading={isFetching}>
						{__('Search', 'power-course')}
					</Button>
				</Item>
			</div>

			{context === 'detail' && (
				<Tags
					products={bundleProductQuery?.data?.data || []}
					isLoading={bundleProductQuery?.isLoading}
				/>
			)}

			<div className="flex justify-between">
				<div className="flex items-center gap-x-4">
					<Item
						name={['compare_last_year']}
						initialValue={false}
						noStyle
						valuePropName="checked"
					>
						<Checkbox>
							{__('Compare with same period last year', 'power-course')}
						</Checkbox>
					</Item>
				</div>
				<div className="flex items-center gap-x-2">
					<Tooltip title={__('Separate view', 'power-course')}>
						<LineChartOutlined
							className={`text-xl ${EViewType.DEFAULT === viewType ? 'text-primary' : 'text-gray-400'}`}
							onClick={() => setViewType(EViewType.DEFAULT)}
						/>
					</Tooltip>
					<Tooltip title={__('Stacked comparison', 'power-course')}>
						<AreaChartOutlined
							className={`text-xl ${EViewType.AREA === viewType ? 'text-primary' : 'text-gray-400'}`}
							onClick={() => setViewType(EViewType.AREA)}
						/>
					</Tooltip>
				</div>
			</div>
		</Form>
	)
}

export default AnalyticsFilter
