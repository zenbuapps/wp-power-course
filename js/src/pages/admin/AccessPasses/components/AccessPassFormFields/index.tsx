import { __ } from '@wordpress/i18n'
import { Form, Input, Divider } from 'antd'
import { memo } from 'react'

import { WatchLimit } from '@/components/formItem'
import { ScopeFields } from '@/pages/admin/AccessPasses/components'

const { Item } = Form

/**
 * 課程權限包表單欄位（Create / Edit 共用）
 *
 * 結構：名稱（必填）→ 範圍設定（ScopeFields，依 scope_type 動態切換）
 * → 期限設定（WatchLimit，依 limit_type 動態切換 unlimited/fixed/assigned/follow_subscription）。
 *
 * 期限設定共用課程端的 WatchLimit 元件，但關閉商品類型耦合
 * （通行證無「課程 / 商品」概念），並改用通行證專屬的 follow_subscription 說明文案。
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
			<WatchLimit
				enableProductTypeWatch={false}
				followSubscriptionAlertContent={__(
					'When Follow Subscription is selected, the access duration follows the subscription status of the product bundled with this access pass',
					'power-course'
				)}
			/>
		</>
	)
}

export const AccessPassFormFields = memo(AccessPassFormFieldsComponent)
