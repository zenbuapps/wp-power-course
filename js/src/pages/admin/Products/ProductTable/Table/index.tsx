import { useTable } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import { Table, FormInstance, Spin, TableProps, Card, Form } from 'antd'
import { useRowSelection } from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import { useSetAtom } from 'jotai'
import { useEffect, memo } from 'react'

import { WatchLimit } from '@/components/formItem'
import {
	BindCourses,
	UpdateBoundCourses,
	UnbindCourses,
} from '@/components/product'
import Filter, {
	initialFilteredValues,
} from '@/components/product/ProductTable/Filter'
import {
	TFilterProps,
	TProductRecord,
	TProductVariation,
	TBindCoursesData,
} from '@/components/product/ProductTable/types'
import {
	onSearch,
	keyLabelMapper,
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { useGCDItems } from '@/hooks'
import { productsAtom } from '@/pages/admin/Products/ProductTable'
import useColumns from '@/pages/admin/Products/ProductTable/hooks/useColumns'
import useValueLabelMapper from '@/pages/admin/Products/ProductTable/hooks/useValueLabelMapper'
import { getInitialFilters } from '@/utils'

const Main = () => {
	const [form] = Form.useForm()
	const watchLimitType = Form.useWatch(['limit_type'], form)

	const { tableProps, searchFormProps } = useTable<
		TProductRecord,
		HttpError,
		TFilterProps
	>({
		resource: 'products',
		dataProviderName: 'power-course',
		onSearch,
		filters: {
			initial: getInitialFilters(initialFilteredValues),
		},
	})

	const { valueLabelMapper } = useValueLabelMapper()

	const { rowSelection, selectedRowKeys, setSelectedRowKeys } =
		useRowSelection<TProductRecord>({
			getCheckboxProps: (record) => {
				// 當觀看期限選擇 follow_subscription "跟隨訂閱" 時，只能選擇訂閱商品
				const isSubscriptionProduct = record?.type?.includes('subscription')
				const disabledForFollowSubscription =
					!isSubscriptionProduct && watchLimitType === 'follow_subscription'

				// 可變商品的母體不必可選，變體可選就好
				const isVariableProduct = record?.type?.startsWith('variable')
				return {
					disabled: !!isVariableProduct || disabledForFollowSubscription,
					className: isVariableProduct ? 'tw-hidden' : '',
				}
			},
		})

	useEffect(() => {
		if ('follow_subscription' === watchLimitType) {
			const subscriptionProductIds = tableProps?.dataSource?.reduce(
				(acc, product) => {
					if ('subscription' === product.type) {
						acc.push(product.id)
					}
					if ('variable-subscription' === product.type) {
						const variationIds =
							product?.children?.map((variation) => variation.id) || []
						acc.push(...variationIds)
					}
					return acc
				},
				[] as string[]
			)

			const removeNonSubscriptionProductIds = selectedRowKeys?.filter((id) =>
				subscriptionProductIds?.includes(id as string)
			)
			setSelectedRowKeys(removeNonSubscriptionProductIds)
		}
	}, [watchLimitType])

	/*
	 * 換頁時，將已加入的商品全局狀態同步到當前頁面的 selectedRowKeys 狀態
	 * 這樣在換頁時，已加入的商品就會被選中
	 */

	const setCourses = useSetAtom(productsAtom)

	useEffect(() => {
		setCourses([...(tableProps?.dataSource || [])])
	}, [tableProps?.dataSource])

	const columns = useColumns()

	const productAllBindCoursesData = selectedRowKeys.map((key) => {
		return tableProps?.dataSource?.find((product) => product.id === key)
			?.bind_courses_data
	})

	const variationAllBindCoursesData = selectedRowKeys.map((key) => {
		const allVariations = tableProps?.dataSource?.reduce((acc, product) => {
			if (product.children) {
				acc.push(...(product.children as TProductVariation[]))
			}
			return acc
		}, [] as TProductVariation[])
		return allVariations?.find((product) => product.id === key)
			?.bind_courses_data
	})

	const selectedAllBindCoursesData = [
		...productAllBindCoursesData,
		...variationAllBindCoursesData,
	].filter((item) => item !== undefined)

	// 取得最大公約數的課程
	const { GcdItemsTags, selectedGCDs, setSelectedGCDs, gcdItems } =
		useGCDItems<TBindCoursesData>({
			allItems: selectedAllBindCoursesData,
		})

	return (
		<Spin spinning={tableProps?.loading as boolean}>
			<Card title="篩選" className="mb-4">
				<Filter
					searchFormProps={searchFormProps}
					optionParams={{
						endpoint: 'products/options',
					}}
				/>
				<div className="mt-2">
					<FilterTags
						form={searchFormProps?.form as FormInstance<TFilterProps>}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
						booleanKeys={[
							'featured',
							'downloadable',
							'virtual',
							'sold_individually',
							'is_course',
						]}
					/>
				</div>
			</Card>
			<Card>
				<Form layout="vertical" form={form}>
					<div className="grid grid-cols-4 gap-x-6">
						<div>
							<WatchLimit />
						</div>
						<div className="col-span-3">
							<div className="mb-4">
								<BindCourses
									product_ids={selectedRowKeys as string[]}
									label="綁定其他課程"
								/>
							</div>
							<div className="mb-4 flex gap-x-6">
								<div>
									{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
									<label className="tw-block mb-2">批次操作</label>
									<div className="flex gap-x-4">
										<UpdateBoundCourses
											product_ids={selectedRowKeys as string[]}
											course_ids={selectedGCDs}
											onSettled={() => {
												setSelectedGCDs([])
											}}
										/>
										<UnbindCourses
											product_ids={selectedRowKeys as string[]}
											course_ids={selectedGCDs}
											onSettled={() => {
												setSelectedGCDs([])
											}}
										/>
									</div>
								</div>
								{!!gcdItems.length && (
									<div className="flex-1">
										{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
										<label className="tw-block mb-2">選擇課程</label>
										<GcdItemsTags />
									</div>
								)}
							</div>
						</div>
					</div>
				</Form>
				<Table
					{...(defaultTableProps as unknown as TableProps<TProductRecord>)}
					{...tableProps}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({ label: '商品' }),
					}}
					rowSelection={rowSelection}
					columns={columns}
				/>
			</Card>
		</Spin>
	)
}

export default memo(Main)
