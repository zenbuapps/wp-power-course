import { Edit, useForm } from '@refinedev/antd'
import { useParsed, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, FormProps, Empty } from 'antd'
import { memo, useMemo } from 'react'

import { AccessPassFormFields } from '@/pages/admin/AccessPasses/components'
import {
	TAccessPassRecord,
	TAccessPassFormValues,
} from '@/pages/admin/AccessPasses/types'

/**
 * 編輯課程權限包頁（Issue #252）
 *
 * 以 Refine `useForm`（edit）+ `<Edit>` 呈現表單，欄位與 Create 共用
 * `AccessPassFormFields`。範圍變更即時生效（compute-on-read）；「將影響 N 位
 * 已購用戶」之提示由列表的刪除流程處理，此處僅提示範圍動態警告（在 ScopeFields 內）。
 *
 * 注意：term_ids / course_ids 在後端為 number[]，但 antd Select 的 options value
 * 為 string，故 initialValues 統一轉為 string[]，避免回填時對不上選項。
 */
const AccessPassesEdit = () => {
	const { id } = useParsed()

	const { formProps, saveButtonProps, onFinish, query, mutation } = useForm<
		TAccessPassRecord,
		HttpError,
		TAccessPassFormValues
	>({
		action: 'edit',
		resource: 'access-passes',
		dataProviderName: 'power-course',
		id,
		redirect: 'list',
	})

	const record = query?.data?.data

	// 將 number[] 轉為 string[] 以對齊 Select options value（string）
	const initialValues = useMemo(() => {
		if (!record) {
			return undefined
		}
		return {
			...record,
			term_ids: (record.term_ids ?? []).map((termId: number | string) =>
				String(termId)
			),
			course_ids: (record.course_ids ?? []).map((courseId: number | string) =>
				String(courseId)
			),
		}
	}, [record])

	/** 送出前依範圍 / 期限模式清掉非當前模式的冗餘欄位 */
	const handleOnFinish = (values: TAccessPassFormValues) => {
		const payload: TAccessPassFormValues = { ...values }

		if ('category' !== payload.scope_type) {
			delete payload.term_ids
		}
		if ('specific' !== payload.scope_type) {
			delete payload.course_ids
		}
		if ('limited' !== payload.limit_mode) {
			delete payload.limit_value
			delete payload.limit_unit
		}

		return onFinish(payload)
	}

	if (!record && query?.isSuccess) {
		return (
			<Empty
				className="mt-[10rem]"
				description={__('Access pass not found', 'power-course')}
			/>
		)
	}

	const mergedFormProps: FormProps = {
		...formProps,
		layout: 'vertical',
		initialValues,
		onFinish: handleOnFinish,
	}

	return (
		<Edit
			resource="access-passes"
			recordItemId={id}
			title={
				<>
					{`${__('Edit access pass', 'power-course')}: ${record?.name ?? ''}`}{' '}
					<span className="text-gray-400 text-xs">#{id}</span>
				</>
			}
			headerButtons={() => null}
			saveButtonProps={{
				...saveButtonProps,
				children: __('Save', 'power-course'),
				loading: mutation?.isLoading,
			}}
			isLoading={query?.isLoading}
		>
			<Form {...mergedFormProps}>
				<AccessPassFormFields />
			</Form>
		</Edit>
	)
}

export default memo(AccessPassesEdit)
