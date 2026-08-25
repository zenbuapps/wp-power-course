import { Edit, useForm } from '@refinedev/antd'
import { useParsed, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, FormProps, Empty, Tabs, Button } from 'antd'
import { memo, useMemo, useState } from 'react'

import { AccessPassFormFields } from '@/pages/admin/AccessPasses/components'
import {
	TAccessPassRecord,
	TAccessPassFormValues,
} from '@/pages/admin/AccessPasses/types'

import { PassHolders } from './tabs/PassHolders'

/** Tab key：settings=通行證設定；holders=持有學員 */
type TTabKey = 'settings' | 'holders'

/**
 * 編輯課程權限包頁（Issue #252）
 *
 * 兩個 Tab：
 *   - 設定：範圍 / 期限 / 名稱，欄位與 Create 共用 `AccessPassFormFields`。
 *     範圍變更即時生效（compute-on-read）；動態範圍警告在 ScopeFields 內。
 *   - 持有學員：誰持有這張通行證、剩多久，並直接手動發放 / 撤銷。
 *
 * 儲存按鈕只在「設定」Tab 顯示——在「持有學員」Tab 按儲存沒有意義，
 * 而且該 Tab 的操作（發放 / 撤銷）本來就是即時生效、不需要儲存。
 *
 * 注意：term_ids / course_ids 在後端為 number[]，但 antd Select 的 options value
 * 為 string，故 initialValues 統一轉為 string[]，避免回填時對不上選項。
 */
const AccessPassesEdit = () => {
	const { id } = useParsed()
	const [activeTab, setActiveTab] = useState<TTabKey>('settings')

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
			footerButtons={() =>
				'settings' === activeTab ? (
					<Button
						type="primary"
						{...saveButtonProps}
						loading={mutation?.isLoading}
					>
						{__('Save', 'power-course')}
					</Button>
				) : null
			}
			isLoading={query?.isLoading}
		>
			<Tabs
				activeKey={activeTab}
				onChange={(key) => setActiveTab(key as TTabKey)}
				items={[
					{
						key: 'settings',
						label: __('Settings', 'power-course'),
						children: (
							<Form {...mergedFormProps}>
								<AccessPassFormFields />
							</Form>
						),
					},
					{
						key: 'holders',
						label: __('Pass holders', 'power-course'),
						children: (
							<PassHolders passId={Number(id)} limitType={record?.limit_type} />
						),
					},
				]}
			/>
		</Edit>
	)
}

export default memo(AccessPassesEdit)
