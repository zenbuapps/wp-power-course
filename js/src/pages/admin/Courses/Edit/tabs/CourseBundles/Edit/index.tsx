import { ExclamationCircleFilled } from '@ant-design/icons'
import { Edit, useForm } from '@refinedev/antd'
import { useLink } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Form, Switch, Alert, message } from 'antd'
import { toFormData, formatDateRangeData } from 'antd-toolkit'
import { Dayjs } from 'dayjs'
import { useAtom, useSetAtom, useAtomValue } from 'jotai'
import React, { memo, useEffect } from 'react'

import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

import {
	selectedProductsAtom,
	courseAtom,
	bundleProductAtom,
	productQuantitiesAtom,
} from './atom'
import BundleForm from './BundleForm'

const EditBundleComponent = ({
	record,
	course,
}: {
	record: TBundleProductRecord
	course: TCourseRecord
}) => {
	const Link = useLink()
	const { id, name } = record

	const selectedProducts = useAtomValue(selectedProductsAtom)
	const [theCourse, setTheCourse] = useAtom(courseAtom)
	const setBundleProduct = useSetAtom(bundleProductAtom)
	const quantities = useAtomValue(productQuantitiesAtom)

	// 初始化資料
	const { formProps, form, saveButtonProps, mutation, onFinish } =
		useForm<TBundleProductRecord>({
			action: 'edit',
			resource: 'bundle_products',
			dataProviderName: 'power-course',
			id,
			redirect: false,
			queryOptions: {
				enabled: false,
			},
			invalidates: ['list'],
			warnWhenUnsavedChanges: true,
		})

	const watchStatus = Form.useWatch(['status'], form)

	useEffect(() => {
		form.setFieldsValue(record)
		setBundleProduct(record)
	}, [record])

	useEffect(() => {
		setTheCourse(course)
	}, [course])

	// 將 [] 轉為 '[]'，例如，清除原本分類時，如果空的，前端會是 undefined，轉成 formData 時會遺失
	const handleOnFinish = () => {
		const values = form.getFieldsValue() as Partial<TBundleProductRecord> & {
			bundle_type: 'bundle'
			sale_date_range: [Dayjs | number, Dayjs | number]
			pbp_product_quantities?: Record<string, number>
		}
		if (!selectedProducts?.length && values?.bundle_type === 'bundle') {
			message.error(__('Please select at least one product', 'power-course'))
			return
		}
		form
			.validateFields()
			.then(() => {
				const formattedValues = formatDateRangeData(values, 'sale_date_range', [
					'date_on_sale_from',
					'date_on_sale_to',
				])

				// 確保 quantities 序列化為 JSON 字串（FormData 不支援嵌套物件）
				if (formattedValues.pbp_product_quantities) {
					;(formattedValues as Record<string, unknown>).pbp_product_quantities =
						JSON.stringify(quantities)
				}

				onFinish(toFormData(formattedValues))
			})
			.catch((_error) => {
				message.error(
					__('Please check if any fields are incomplete', 'power-course')
				)
			})
	}

	if (!theCourse) {
		return null
	}
	return (
		<Edit
			resource="bundle_products"
			dataProviderName="power-course"
			recordItemId={id}
			breadcrumb={null}
			goBack={null}
			headerButtons={() => null}
			title={
				<div className="pl-4">
					{sprintf(
						/* translators: %s: 銷售方案名稱 */
						__('Edit: %s', 'power-course'),
						name
					)}{' '}
					<span className="text-gray-400 text-xs">#{id}</span>
				</div>
			}
			saveButtonProps={{
				...saveButtonProps,
				children: __('Save bundle', 'power-course'),
				icon: null,
				loading: mutation?.isLoading,
				onClick: handleOnFinish,
			}}
			footerButtons={({ defaultButtons }) => (
				<>
					<div className="text-red-500 font-bold mr-8">
						<ExclamationCircleFilled />{' '}
						{__(
							'Bundles are saved separately. Remember to save after editing',
							'power-course'
						)}
					</div>

					<Switch
						className="mr-4"
						checkedChildren={__('Published', 'power-course')}
						unCheckedChildren={__('Draft', 'power-course')}
						value={watchStatus === 'publish'}
						onChange={(checked) => {
							form.setFieldValue(['status'], checked ? 'publish' : 'draft')
						}}
					/>
					{defaultButtons}
				</>
			)}
			wrapperProps={{
				style: {
					boxShadow: '0px 0px 16px 0px #ddd',
					paddingTop: '1rem',
					borderRadius: '0.5rem',
				},
			}}
		>
			<Form {...formProps} layout="vertical">
				<Alert
					className="mb-4"
					message={__('Notes', 'power-course')}
					description={
						<ol className="pl-4">
							<li>
								<b>{__('Bundle deal', 'power-course')}</b>
								{__(
									': You may leave this course unlinked. If this course is not linked, course access will not be granted automatically, and it can be used as an add-on product',
									'power-course'
								)}
							</li>
							<li>
								<b>{__('Subscription', 'power-course')}</b>
								{__(
									': By default, the course access duration will be tied to the subscription product in this bundle. Please make sure the access duration is already saved',
									'power-course'
								)}
							</li>
							<li>
								{__('Bundles are products themselves. You can go to', 'power-course')}
								<Link to="/products">
									{' '}
									{__('Course Access Binding', 'power-course')}{' '}
								</Link>
								{__(
									'to further adjust course access and viewing duration',
									'power-course'
								)}
							</li>
						</ol>
					}
					type="warning"
					showIcon
					closable
				/>
				<BundleForm />
			</Form>
		</Edit>
	)
}

export const EditBundle = memo(EditBundleComponent)
