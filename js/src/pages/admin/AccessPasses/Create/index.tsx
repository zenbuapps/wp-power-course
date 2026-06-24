import { Create, useForm } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, FormProps } from 'antd'
import { memo } from 'react'

import { AccessPassFormFields } from '@/pages/admin/AccessPasses/components'
import {
	TAccessPassRecord,
	TAccessPassFormValues,
} from '@/pages/admin/AccessPasses/types'

/**
 * 新增課程權限包頁（Issue #252）
 *
 * 以 Refine `useForm`（create）+ `<Create>` 呈現表單，
 * 欄位與 Edit 共用 `AccessPassFormFields`（name + 範圍 + 期限動態切換）。
 * 建立成功後導回列表。
 */
const AccessPassesCreate = () => {
	const { formProps, saveButtonProps, onFinish } = useForm<
		TAccessPassRecord,
		HttpError,
		TAccessPassFormValues
	>({
		action: 'create',
		resource: 'access-passes',
		dataProviderName: 'power-course',
		redirect: 'list',
	})

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

	const mergedFormProps: FormProps = {
		...formProps,
		layout: 'vertical',
		onFinish: handleOnFinish,
	}

	return (
		<Create
			resource="access-passes"
			title={__('Add access pass', 'power-course')}
			saveButtonProps={{
				...saveButtonProps,
				children: __('Create', 'power-course'),
			}}
		>
			<Form {...mergedFormProps}>
				<AccessPassFormFields />
			</Form>
		</Create>
	)
}

export default memo(AccessPassesCreate)
