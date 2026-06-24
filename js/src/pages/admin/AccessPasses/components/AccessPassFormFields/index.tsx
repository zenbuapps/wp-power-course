import { __ } from '@wordpress/i18n'
import { Form, Input, Divider } from 'antd'
import { memo } from 'react'

import { LimitFields, ScopeFields } from '@/pages/admin/AccessPasses/components'

const { Item } = Form

/**
 * 課程權限包表單欄位（Create / Edit 共用）
 *
 * 結構：名稱（必填）→ 範圍設定（ScopeFields，依 scope_type 動態切換）
 * → 期限設定（LimitFields，依 limit_mode 動態切換）。
 *
 * 須置於 antd `<Form>`（或 Refine `formProps`）內使用。
 */
const AccessPassFormFieldsComponent = () => {
	return (
		<>
			<Item
				label={__('Access pass name', 'power-course')}
				name={['name']}
				tooltip={__(
					'For internal management only, will not be shown to users',
					'power-course'
				)}
				rules={[
					{
						required: true,
						message: __('Please enter access pass name', 'power-course'),
					},
				]}
			>
				<Input allowClear />
			</Item>

			<Divider orientation="left" orientationMargin={0}>
				{__('Scope', 'power-course')}
			</Divider>
			<ScopeFields />

			<Divider orientation="left" orientationMargin={0}>
				{__('Access period', 'power-course')}
			</Divider>
			<LimitFields />
		</>
	)
}

export const AccessPassFormFields = memo(AccessPassFormFieldsComponent)
