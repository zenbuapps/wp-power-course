import {
	CheckOutlined,
	PlusOutlined,
	ExclamationCircleOutlined,
} from '@ant-design/icons'
import { useList } from '@refinedev/core'
import { Form, Input, Tag, List, Select, Switch, InputNumber } from 'antd'
import { renderHTML } from 'antd-toolkit'
import { useAtomValue, useAtom } from 'jotai'
import React, { useState, memo, useEffect } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'
import { FiSwitch } from '@/components/formItem'
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
	PRODUCT_TYPE_OPTIONS,
	getPrice,
} from './utils'

const { Search } = Input
const { Item } = Form

const BundleForm = () => {
	const course = useAtomValue(courseAtom)
	const record = useAtomValue(bundleProductAtom)
	const [selectedProducts, setSelectedProducts] = useAtom(selectedProductsAtom)
	const [productQuantities, setProductQuantities] = useAtom(
		productQuantitiesAtom
	)

	const {
		id: courseId,
		name: courseName,
		price_html: coursePrice,
	} = course as TCourseRecord

	const [searchKeyWord, setSearchKeyWord] = useState<string>('')
	const [showList, setShowList] = useState<boolean>(false)
	const bundleProductForm = Form.useFormInstance()
	const watchExcludeMainCourse =
		Form.useWatch(['exclude_main_course'], bundleProductForm) === 'yes'

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
				field: 'exclude',
				operator: 'eq',
				value: [courseId],
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
		} else {
			// 當前列表中沒有這個商品，所以要加入

			setSelectedProducts([...selectedProducts, product])
		}
	}

	// 將當前商品移除
	const initPIdsExcludedCourseId = (
		record?.[INCLUDED_PRODUCT_IDS_FIELD_NAME] || []
	).filter((id) => id !== courseId)

	// 初始狀態
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
			// 初始化商品
			setSelectedProducts(includedProducts)
			// 從 API 回傳的 pbp_product_quantities 初始化數量 atom
			const savedQuantities = record?.pbp_product_quantities ?? {}
			setProductQuantities(savedQuantities)
		}
	}, [initIsFetching])

	useEffect(() => {
		// 選擇商品改變時，同步更新到表單上
		const productIds = watchExcludeMainCourse
			? selectedProducts.map(({ id }) => id)
			: [
					courseId,
					...selectedProducts.map(({ id }) => id),
				]
		bundleProductForm.setFieldValue(
			[INCLUDED_PRODUCT_IDS_FIELD_NAME],
			productIds
		)

		bundleProductForm.setFieldValue(
			['regular_price'],
			getPrice({
				type: 'regular_price',
				products: selectedProducts,
				course,
				excludeMainCourse: watchExcludeMainCourse,
				quantities: productQuantities,
			})
		)

		// 同步 pbp_product_quantities 到表單隱藏欄位
		bundleProductForm.setFieldValue(
			['pbp_product_quantities'],
			productQuantities
		)
	}, [selectedProducts.length, watchExcludeMainCourse, productQuantities])

	const bundlePrices = {
		regular_price: getPrice({
			isFetching: initIsFetching,
			type: 'regular_price',
			products: selectedProducts,
			course,
			returnType: 'string',
			excludeMainCourse: watchExcludeMainCourse,
			quantities: productQuantities,
		}),
		sale_price: getPrice({
			isFetching: initIsFetching,
			type: 'sale_price',
			products: selectedProducts,
			course,
			returnType: 'string',
			excludeMainCourse: watchExcludeMainCourse,
			quantities: productQuantities,
		}),
	}

	return (
		<>
			<Item name={['id']} hidden />
			<Item name={['pbp_product_quantities']} hidden />
			<Gallery limit={1} />
			<Item
				name={['bundle_type']}
				label="銷售方案種類"
				initialValue={BUNDLE_TYPE_OPTIONS[0].value}
				hidden={false}
			>
				<Select options={BUNDLE_TYPE_OPTIONS} />
			</Item>
			<Item
				name={['type']}
				label="銷售方案商品種類"
				initialValue={PRODUCT_TYPE_OPTIONS[0].value}
			>
				<Select options={PRODUCT_TYPE_OPTIONS} />
			</Item>
			<Item
				name={['bundle_type_label']}
				label="銷售方案種類顯示文字"
				tooltip="銷售方案名稱上方的紅色小字"
			>
				<Input />
			</Item>
			<Item
				name={['name']}
				label="銷售方案名稱"
				rules={[
					{
						required: true,
						message: '請輸入銷售方案名稱',
					},
				]}
			>
				<Input />
			</Item>
			<Item name={['purchase_note']} label="銷售方案說明">
				<Input.TextArea rows={8} />
			</Item>

			<Item name={[INCLUDED_PRODUCT_IDS_FIELD_NAME]} initialValue={[]} hidden />

			<Heading className="mb-3">自由搭配你的銷售方案，選擇要加入的商品</Heading>
			<FiSwitch
				formItemProps={{
					name: ['exclude_main_course'],
					label: '排除目前課程',
				}}
				switchProps={{
					size: 'small',
				}}
			/>

			<div className="border-2 border-dashed rounded-xl p-4 mb-8 border-blue-500">
				{/* 當前課程方案 */}
				<div
					className={`flex items-center justify-between gap-4 border border-solid border-gray-200 p-2 rounded-md ${watchExcludeMainCourse ? 'opacity-20 saturate-0' : ''}`}
				>
					<img
						alt=""
						src={course?.images?.[0]?.url || defaultImage}
						className="h-9 w-16 rounded object-cover"
					/>
					<div className="w-full">
						{courseName} #{courseId} {renderHTML(coursePrice || '')}
					</div>
					<div className="flex items-center gap-2">
						<span className="text-xs text-gray-500 whitespace-nowrap">
							數量
						</span>
						<InputNumber
							min={1}
							defaultValue={1}
							value={productQuantities[courseId] ?? 1}
							onChange={(value) => {
								setProductQuantities({
									...productQuantities,
									[courseId]: value ?? 1,
								})
							}}
							disabled={watchExcludeMainCourse}
							size="small"
							className="w-16"
						/>
					</div>
					<div>
						<Tag color="blue">目前課程</Tag>
					</div>
				</div>
				{/* END 當前課程方案 */}
				<div
					className={`text-center my-2 ${watchExcludeMainCourse ? 'opacity-0' : ''}`}
				>
					<PlusOutlined />
				</div>
				<div className="text-primary">
					<ExclamationCircleOutlined className="mr-2" />
					您也可以選擇不加入產品，單純創建課程的定期定額銷售方案
				</div>
				<div className="relative mb-2">
					<Search
						placeholder="請輸入關鍵字後按下 ENTER 搜尋，每次最多返回 20 筆資料"
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

				{!initIsFetching &&
					selectedProducts?.map(({ id, images, name, price_html, type }) => {
						const tag = productTypes.find(
							(productType) => productType.value === type
						)

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
								</div>
								<div className="flex items-center gap-2">
									<span className="text-xs text-gray-500 whitespace-nowrap">
										數量
									</span>
									<InputNumber
										min={1}
										defaultValue={1}
										value={productQuantities[id] ?? 1}
										onChange={(value) => {
											setProductQuantities({
												...productQuantities,
												[id]: value ?? 1,
											})
										}}
										size="small"
										className="w-16"
									/>
								</div>
								<div>
									<Tag bordered={false} color={tag?.color} className="m-0">
										{tag?.label}
									</Tag>
								</div>
								<div className="w-8 text-right">
									<PopconfirmDelete
										popconfirmProps={{
											onConfirm: () => {
												setSelectedProducts(
													selectedProducts?.filter(
														({ id: productId }) => productId !== id
													)
												)
											},
										}}
									/>
								</div>
							</div>
						)
					})}

				{/* Loading */}
				{initIsFetching &&
					initPIdsExcludedCourseId.map((id) => (
						<div
							key={id}
							className="flex items-center justify-start gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2 animate-pulse"
						>
							<div className="bg-slate-300 h-9 w-16 rounded object-cover" />
							<div>
								<div className="bg-slate-300 h-3 w-20 mb-1" />
								<div className="bg-slate-300 size-32" />
							</div>
						</div>
					))}
			</div>

			<ProductPriceFields bundlePrices={bundlePrices} />

			<div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
				<Item name={['virtual']} label="虛擬商品">
					<Switch />
				</Item>
				<Item name={['status']} hidden />
			</div>
		</>
	)
}

export default memo(BundleForm)
