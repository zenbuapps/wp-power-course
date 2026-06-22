import {
	CheckOutlined,
	PlusOutlined,
	ExclamationCircleOutlined,
} from '@ant-design/icons'
import { useList } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Form, Input, InputNumber, Tag, List, Select, Switch } from 'antd'
import { renderHTML } from 'antd-toolkit'
import { useAtomValue, useAtom } from 'jotai'
import React, { useState, memo, useEffect } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'
import { DatePicker } from '@/components/formItem'
import { PopconfirmDelete, Heading } from '@/components/general'
import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'
import { productTypes } from '@/utils'

import {
	courseAtom,
	selectedProductsAtom,
	bundleProductAtom,
	productQuantitiesAtom,
} from './atom'
import Gallery from './Gallery'
import ProductPriceFields from './ProductPriceFields'
import {
	BUNDLE_TYPE_OPTIONS,
	INCLUDED_PRODUCT_IDS_FIELD_NAME,
	PRODUCT_QUANTITIES_FIELD_NAME,
	PRODUCT_TYPE_OPTIONS,
	getPrice,
} from './utils'

const { Search } = Input
const { Item } = Form

const BundleForm = () => {
	const course = useAtomValue(courseAtom)
	const record = useAtomValue(bundleProductAtom)
	const [selectedProducts, setSelectedProducts] = useAtom(selectedProductsAtom)
	const [quantities, setQuantities] = useAtom(productQuantitiesAtom)

	const { id: courseId, name: courseName } = course as TCourseRecord

	const [searchKeyWord, setSearchKeyWord] = useState<string>('')
	const [showList, setShowList] = useState<boolean>(false)
	const bundleProductForm = Form.useFormInstance()

	const onSearch = (value: string) => {
		setSearchKeyWord(value)
		setShowList(true)
	}

	const searchProductsResult = useList<TBundleProductRecord>({
		dataProviderName: 'power-course',
		resource: 'products',
		filters: [
			{
				field: 's',
				operator: 'eq',
				value: searchKeyWord,
			},
			{
				field: 'status',
				operator: 'eq',
				value: 'publish',
			},
			{
				field: 'type',
				operator: 'in',
				value: ['simple', 'subscription'],
			},
			{
				field: 'meta_key',
				operator: 'eq',
				value: 'link_course_ids',
			},
			{
				field: 'meta_compare',
				operator: 'eq',
				value: 'NOT EXISTS',
			},
		],
		pagination: {
			pageSize: 20,
		},
	})

	const searchProducts = searchProductsResult.data?.data || []

	// 處理點擊商品，有可能是加入也可能是移除
	const handleClick = (product: TBundleProductRecord) => () => {
		const isInclude = selectedProducts?.some(({ id }) => id === product.id)
		if (isInclude) {
			// 當前列表中已經有這個商品，所以要移除
			setSelectedProducts(
				selectedProducts.filter(({ id }) => id !== product.id)
			)
			// 同時移除 quantities 中對應的數量
			setQuantities((prev) => {
				const next = { ...prev }
				delete next[String(product.id)]
				return next
			})
		} else {
			// 當前列表中沒有這個商品，所以要加入
			setSelectedProducts([...selectedProducts, product])
			// 新加入的商品預設數量為 1
			setQuantities((prev) => ({
				...prev,
				[String(product.id)]: prev[String(product.id)] ?? 1,
			}))
		}
	}

	// 初始狀態：從 record 中載入所有商品（含當前課程）
	const initProductIds = record?.[INCLUDED_PRODUCT_IDS_FIELD_NAME] || []
	const initPIdsExcludedCourseId = initProductIds.filter(
		(id) => id !== courseId
	)

	const { data: initProductsData, isFetching: initIsFetching } =
		useList<TBundleProductRecord>({
			dataProviderName: 'power-course',
			resource: 'products',
			filters: [
				{
					field: 'include',
					operator: 'eq',
					value: initPIdsExcludedCourseId,
				},
			],
			queryOptions: {
				// 剛進來的時候才需要 fetch
				enabled: !!initPIdsExcludedCourseId?.length,
			},
		})

	const includedProducts = initProductsData?.data || []

	useEffect(() => {
		if (!initIsFetching) {
			// Issue #249: 以後端回傳的 pbp_product_ids 為唯一真相來源，依其順序還原 selectedProducts。
			// 不再無條件補入當前課程——課程是否在方案中，完全由 pbp_product_ids 決定，
			// 尊重使用者「移除課程」的狀態（移除後 pbp_product_ids 不含 courseId，就不會再被帶回）。
			const productMap = new Map(
				includedProducts.map((product) => [String(product.id), product])
			)
			const initProducts = initProductIds.reduce<TBundleProductRecord[]>(
				(acc, id) => {
					if (String(id) === String(courseId)) {
						// 當前課程的商品物件不在 includedProducts（查詢已排除 courseId），改用 course
						if (course) {
							acc.push(course as unknown as TBundleProductRecord)
						}
						return acc
					}
					const product = productMap.get(String(id))
					if (product) {
						acc.push(product)
					}
					return acc
				},
				[]
			)

			setSelectedProducts(initProducts)

			// 初始化 quantities
			const initQuantities = record?.pbp_product_quantities ?? {}
			setQuantities(initQuantities)
		}
	}, [initIsFetching])

	useEffect(() => {
		// 選擇商品改變時，同步更新到表單上
		const courseInSelected = selectedProducts.some(
			({ id }) => String(id) === String(courseId)
		)
		const otherProducts = selectedProducts.filter(
			({ id }) => String(id) !== String(courseId)
		)

		// Issue #249: pbp_product_ids 直接依 selectedProducts 當前順序推導，
		// 不再強制把 courseId 放到第一位。如此「移除課程」後 courseId 不在 selectedProducts，
		// 就不會被覆寫帶回，尊重使用者的移除狀態。
		const productIds = selectedProducts.map(({ id }) => id)

		bundleProductForm.setFieldValue(
			[INCLUDED_PRODUCT_IDS_FIELD_NAME],
			productIds
		)

		// Issue #249: bind_course_ids 依「課程是否仍在方案列表」動態推導，
		// 不再寫死 [courseId]。課程在列表才綁定、移除則不綁，
		// 避免儲存時又把課程灌回 bind_courses_data（第三條病灶）。
		bundleProductForm.setFieldValue(
			['bind_course_ids'],
			courseInSelected ? [courseId] : []
		)

		// 同步 quantities 到表單
		bundleProductForm.setFieldValue([PRODUCT_QUANTITIES_FIELD_NAME], quantities)

		// 同步價格
		bundleProductForm.setFieldValue(
			['regular_price'],
			getPrice({
				type: 'regular_price',
				products: otherProducts,
				course: courseInSelected ? course : undefined,
				quantities,
				courseId: courseInSelected ? String(courseId) : undefined,
			})
		)
	}, [selectedProducts.length, quantities])

	const courseInSelected = selectedProducts.some(
		({ id }) => String(id) === String(courseId)
	)
	const otherProducts = selectedProducts.filter(
		({ id }) => String(id) !== String(courseId)
	)

	const bundlePrices = {
		regular_price: getPrice({
			isFetching: initIsFetching,
			type: 'regular_price',
			products: otherProducts,
			course: courseInSelected ? course : undefined,
			returnType: 'string',
			quantities,
			courseId: courseInSelected ? String(courseId) : undefined,
		}),
		sale_price: getPrice({
			isFetching: initIsFetching,
			type: 'sale_price',
			products: otherProducts,
			course: courseInSelected ? course : undefined,
			returnType: 'string',
			quantities,
			courseId: courseInSelected ? String(courseId) : undefined,
		}),
	}

	return (
		<>
			<Item name={['id']} hidden />
			<Gallery limit={1} />
			<Item
				name={['bundle_type']}
				label={__('Bundle Type', 'power-course')}
				initialValue={BUNDLE_TYPE_OPTIONS[0].value}
				hidden={false}
			>
				<Select options={BUNDLE_TYPE_OPTIONS} />
			</Item>
			<Item
				name={['type']}
				label={__('Bundle Product Type', 'power-course')}
				initialValue={PRODUCT_TYPE_OPTIONS[0].value}
			>
				<Select options={PRODUCT_TYPE_OPTIONS} />
			</Item>
			<Item
				name={['bundle_type_label']}
				label={__('Bundle Type Display Text', 'power-course')}
				tooltip={__('The red small text above the bundle name', 'power-course')}
			>
				<Input />
			</Item>
			<Item
				name={['name']}
				label={__('Bundle Name', 'power-course')}
				rules={[
					{
						required: true,
						message: __('Please enter the bundle name', 'power-course'),
					},
				]}
			>
				<Input />
			</Item>
			<Item
				name={['purchase_note']}
				label={__('Bundle Description', 'power-course')}
			>
				<Input.TextArea rows={8} />
			</Item>

			<Item name={[INCLUDED_PRODUCT_IDS_FIELD_NAME]} initialValue={[]} hidden />
			<Item name={[PRODUCT_QUANTITIES_FIELD_NAME]} initialValue={{}} hidden />

			<Heading className="mb-3">
				{__(
					'Freely customize your bundle by selecting products to include',
					'power-course'
				)}
			</Heading>

			<div className="border-2 border-dashed rounded-xl p-4 mb-8 border-blue-500">
				<div className="text-primary mb-2">
					<ExclamationCircleOutlined className="mr-2" />
					{__(
						'You may also skip adding any product and simply create a subscription bundle for the course',
						'power-course'
					)}
				</div>
				<div className="relative mb-2">
					<Search
						placeholder={__(
							'Enter keywords and press ENTER to search. Up to 20 results per query',
							'power-course'
						)}
						allowClear
						onSearch={onSearch}
						enterButton
						loading={searchProductsResult.isFetching}
						onClick={() => setShowList(!showList)}
					/>
					<div
						className={`absolute border border-solid border-gray-200 rounded-md shadow-lg top-[100%] w-full bg-white z-50 max-h-[30rem] overflow-y-auto ${showList ? 'tw-block' : 'tw-hidden'}`}
						onMouseLeave={() => setShowList(false)}
					>
						<List
							rowKey="id"
							dataSource={searchProducts}
							renderItem={(product) => {
								const { id, images, name, price_html } = product
								const isInclude = selectedProducts?.some(
									({ id: theId }) => theId === product.id
								)
								const tag = productTypes.find(
									(productType) => productType.value === product.type
								)
								return (
									<div
										key={id}
										className={`flex items-center justify-between gap-4 p-2 mb-0 cursor-pointer hover:bg-blue-100 ${isInclude ? 'bg-blue-100' : 'bg-white'}`}
										onClick={handleClick(product)}
									>
										<img
											alt=""
											src={images?.[0]?.url || defaultImage}
											className="h-9 w-16 rounded object-cover"
										/>
										<div className="w-full">
											<span className="text-gray-400 text-xs">#{id}</span>
											{name}
											<br />
											{renderHTML(price_html)}
										</div>
										<div>
											<Tag bordered={false} color={tag?.color} className="m-0">
												{tag?.label}
											</Tag>
										</div>
										<div className="w-8 text-center">
											{isInclude && <CheckOutlined className="text-blue-500" />}
										</div>
									</div>
								)
							}}
						/>
					</div>
				</div>

				{/* 已選商品列表（含當前課程） */}
				{!initIsFetching &&
					selectedProducts?.map(({ id, images, name, price_html, type }) => {
						const tag = productTypes.find(
							(productType) => productType.value === type
						)
						const isCourse = String(id) === String(courseId)

						return (
							<div
								key={id}
								className="flex items-center justify-between gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2"
							>
								<div className="rounded aspect-video w-16 overflow-hidden">
									<img
										alt=""
										src={images?.[0]?.url || defaultImage}
										className="w-full h-full rounded object-cover"
									/>
								</div>
								<div className="flex-1">
									{name} #{id} {renderHTML(price_html)}
									{isCourse && (
										<Tag color="blue" className="ml-1">
											{__('Current Course', 'power-course')}
										</Tag>
									)}
								</div>
								<div>
									<Tag bordered={false} color={tag?.color} className="m-0">
										{tag?.label}
									</Tag>
								</div>
								{/* 數量 InputNumber */}
								<InputNumber
									min={1}
									max={999}
									value={quantities[String(id)] ?? 1}
									onChange={(val) => {
										setQuantities((prev) => ({
											...prev,
											[String(id)]: Math.max(1, val ?? 1),
										}))
									}}
									className="w-20"
									size="small"
								/>
								<div className="w-8 text-right">
									<PopconfirmDelete
										popconfirmProps={{
											onConfirm: () => {
												setSelectedProducts(
													selectedProducts?.filter(
														({ id: productId }) => productId !== id
													)
												)
												// 移除商品時也清除 quantities
												setQuantities((prev) => {
													const next = { ...prev }
													delete next[String(id)]
													return next
												})
											},
										}}
									/>
								</div>
							</div>
						)
					})}

				{/* Loading */}
				{initIsFetching &&
					initProductIds.map((id) => (
						<div
							key={id}
							className="flex items-center justify-between gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2 animate-pulse"
						>
							<div className="bg-slate-300 aspect-video w-16 rounded shrink-0" />
							<div className="flex-1">
								<div className="bg-slate-300 h-3 w-2/5 rounded mb-1" />
								<div className="bg-slate-300 h-3 w-1/4 rounded" />
							</div>
							<div className="bg-slate-300 h-5 w-16 rounded shrink-0" />
							<div className="bg-slate-300 h-6 w-20 rounded shrink-0" />
							<div className="bg-slate-300 h-6 w-8 rounded shrink-0" />
						</div>
					))}

				{/* 提示新增當前課程按鈕（若當前課程不在列表中） */}
				{!initIsFetching && !courseInSelected && course && (
					<div
						className="flex items-center gap-2 text-blue-500 cursor-pointer hover:bg-blue-50 p-2 rounded-md border border-dashed border-blue-300 mb-2"
						onClick={() => {
							setSelectedProducts([
								...selectedProducts,
								course as unknown as TBundleProductRecord,
							])
							setQuantities((prev) => ({
								...prev,
								[String(courseId)]: prev[String(courseId)] ?? 1,
							}))
						}}
					>
						<PlusOutlined />
						<span>
							{sprintf(
								/* translators: %s: 課程名稱 */
								__('Add current course %s', 'power-course'),
								courseName
							)}
						</span>
					</div>
				)}
			</div>

			<ProductPriceFields bundlePrices={bundlePrices} />

			<Heading className="mb-3">
				{__('Auto online/offline schedule', 'power-course')}
			</Heading>
			<div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
				<DatePicker
					formItemProps={{
						name: ['bundle_schedule_online'],
						label: __('Auto online time', 'power-course'),
						className: 'mb-0',
					}}
				/>
				<DatePicker
					formItemProps={{
						name: ['bundle_schedule_offline'],
						label: __('Auto offline time', 'power-course'),
						className: 'mb-0',
					}}
				/>
			</div>
			<p className="text-gray-400 text-xs mt-1 mb-6">
				{__('Scheduling is based on the site timezone', 'power-course')}
			</p>

			<div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
				<Item name={['virtual']} label={__('Virtual Product', 'power-course')}>
					<Switch />
				</Item>
				<Item name={['status']} hidden />
			</div>
		</>
	)
}

export default memo(BundleForm)
