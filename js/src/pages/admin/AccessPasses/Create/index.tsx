import { Create, useForm } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, FormProps } from 'antd'
import { memo, useEffect } from 'react'

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

	// 進入建立頁時顯式重置為乾淨初始狀態（scope radio、課程 / 分類多選、期限欄位）。
	// 目前路由切換會 unmount/remount 本元件、Refine create useForm 亦不快取表單值，
	// 正常情況下每次進入本已是空表單；此處明確保證該不變式，避免未來路由結構
	// （如 keep-alive）或 Refine 版本變動時殘留上一次填寫的範圍 / 已選課程。
	useEffect(() => {
		formProps.form?.resetFields()
		// 僅在掛載時重置一次
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [])

	/** 送出前依範圍 / 期限類型清掉非當前類型的冗餘欄位 */
	const handleOnFinish = (values: TAccessPassFormValues) => {
		const payload: TAccessPassFormValues = { ...values }

		if ('category' !== payload.scope_type) {
			delete payload.term_ids
		}
		if ('specific' !== payload.scope_type) {
			delete payload.course_ids
		}
		// fixed / assigned 保留 limit_value + limit_unit；unlimited / follow_subscription 清空
		if ('fixed' !== payload.limit_type && 'assigned' !== payload.limit_type) {
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
