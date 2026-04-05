import {
	CheckOutlined,
	PlusOutlined,
	ExclamationCircleOutlined,
} from '@ant-design/icons'
import { useList } from '@refinedev/core'
import { Form, Input, InputNumber, Tag, List, Select, Switch } from 'antd'
import { renderHTML } from 'antd-toolkit'
import { useAtomValue, useAtom } from 'jotai'
import React, { useState, memo, useEffect } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'
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

	const {
		id: courseId,
		name: courseName,
		price_html: coursePrice,
	} = course as TCourseRecord

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
				selectedProducts.filter(({ id }) => id !== product.id),
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
		(id) => id !== courseId,
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
			// 初始化商品（含當前課程判斷）
			const initProducts = [...includedProducts]

			// 如果當前課程在 pbp_product_ids 中，且不在 initProducts 中，加入 selectedProducts
			if (
				initProductIds.includes(courseId) &&
				!initProducts.some(({ id }) => String(id) === String(courseId))
			) {
				if (course) {
					initProducts.unshift(course as unknown as TBundleProductRecord)
				}
			}

			setSelectedProducts(initProducts)

			// 初始化 quantities
			const initQuantities = record?.pbp_product_quantities ?? {}
			setQuantities(initQuantities)
		}
	}, [initIsFetching])

	useEffect(() => {
		// 選擇商品改變時，同步更新到表單上
		const courseInSelected = selectedProducts.some(
			({ id }) => String(id) === String(courseId),
		)
		const otherProducts = selectedProducts.filter(
			({ id }) => String(id) !== String(courseId),
		)
		const productIds = courseInSelected
			? [courseId, ...otherProducts.map(({ id }) => id)]
			: otherProducts.map(({ id }) => id)

		bundleProductForm.setFieldValue([INCLUDED_PRODUCT_IDS_FIELD_NAME], productIds)

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
			}),
		)
	}, [selectedProducts.length, quantities])

	const courseInSelected = selectedProducts.some(
		({ id }) => String(id) === String(courseId),
	)
	const otherProducts = selectedProducts.filter(
		({ id }) => String(id) !== String(courseId),
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
			<Item
				name={[PRODUCT_QUANTITIES_FIELD_NAME]}
				initialValue={{}}
				hidden
			/>

			<Heading className="mb-3">自由搭配你的銷售方案，選擇要加入的商品</Heading>

			<div className="border-2 border-dashed rounded-xl p-4 mb-8 border-blue-500">
				<div className="text-primary mb-2">
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
									({ id: theId }) => theId === product.id,
								)
								const tag = productTypes.find(
									(productType) => productType.value === product.type,
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
											{isInclude && (
												<CheckOutlined className="text-blue-500" />
											)}
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
							(productType) => productType.value === type,
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
											目前課程
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
														({ id: productId }) => productId !== id,
													),
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
							className="flex items-center justify-start gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2 animate-pulse"
						>
							<div className="bg-slate-300 h-9 w-16 rounded object-cover" />
							<div>
								<div className="bg-slate-300 h-3 w-20 mb-1" />
								<div className="bg-slate-300 size-32" />
							</div>
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
						<span>加入目前課程 {courseName}</span>
					</div>
				)}
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
