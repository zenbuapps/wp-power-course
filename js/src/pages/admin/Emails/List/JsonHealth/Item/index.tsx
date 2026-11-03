import { useLink, useNavigation } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Button, Card, Popconfirm, Tag, Typography } from 'antd'
import { Fragment } from 'react'

import {
	EMAIL_JSON_HEALTH_STATE,
	type TEmailJsonHealthItem,
} from '@/pages/admin/Emails/types'
import { getPostStatus } from '@/utils'

import { useRepairEmailJson } from '../hooks'

/** 修復前後內容共用的樣式 */
const CHANGE_CLASS_NAME =
	'm-0 p-2 rounded bg-gray-50 font-mono text-xs whitespace-pre-wrap break-all'

/**
 * 取得郵件模板狀態的顯示文字
 *
 * 啟用 / 停用與列表一致。檢查範圍另外包含垃圾桶，要明確標示，不能顯示成「已停用」。
 *
 * @param status post status
 */
const getStatusLabel = (status: string) => {
	if ('trash' === status) {
		return __('In trash', 'power-course')
	}

	return 'publish' === status
		? __('Enabled', 'power-course')
		: __('Disabled', 'power-course')
}

type TItemProps = {
	/** 損壞的郵件模板與修復預覽 */
	item: TEmailJsonHealthItem
	/** 清單是否正在重新取得：此時 fingerprint 可能已過期，不可送出修復 */
	isRefreshing: boolean
}

/**
 * 單一損壞郵件模板的檢視與修復
 * 可修復時顯示每筆修復前後內容，並在明確確認後修復；寄送 HTML 損壞時提示回編輯器重新儲存；否則只提示聯絡技術支援。
 */
const Item = ({ item, isRefreshing }: TItemProps) => {
	const { id, name, status, state, fingerprint, changes } = item
	const Link = useLink()
	const { editUrl } = useNavigation()
	const { repair, isRepairing } = useRepairEmailJson(id)
	const isRepairable = EMAIL_JSON_HEALTH_STATE.REPAIRABLE === state
	const isHtmlDamaged = EMAIL_JSON_HEALTH_STATE.HTML_DAMAGED === state

	return (
		<Card
			size="small"
			title={
				<>
					<Link to={editUrl('emails', id)}>{name || `#${id}`}</Link>{' '}
					<span className="text-gray-400 text-xs">#{id}</span>
				</>
			}
			extra={
				<div className="flex items-center gap-2">
					<Tag color={getPostStatus(status).color}>
						{getStatusLabel(status)}
					</Tag>
					{isHtmlDamaged && (
						<Tag color="orange">{__('Needs re-saving', 'power-course')}</Tag>
					)}
					{isRepairable && (
						<>
							<Tag color="green">{__('Repairable', 'power-course')}</Tag>
							<Popconfirm
								title={__('Repair this email template?', 'power-course')}
								description={__(
									'The original content will be backed up first, and only the editor content will be updated.',
									'power-course'
								)}
								okText={__('Repair', 'power-course')}
								cancelText={__('Cancel', 'power-course')}
								onConfirm={() => repair(fingerprint)}
							>
								<Button
									size="small"
									type="primary"
									loading={isRepairing}
									disabled={isRefreshing}
								>
									{__('Repair', 'power-course')}
								</Button>
							</Popconfirm>
						</>
					)}
					{EMAIL_JSON_HEALTH_STATE.UNREPAIRABLE === state && (
						<Tag color="red">{__('Cannot be repaired', 'power-course')}</Tag>
					)}
				</div>
			}
		>
			{isHtmlDamaged && (
				<Typography.Text>
					{__(
						'The HTML used for sending was altered, so CSS may appear as text in sent emails. Open this email in the editor and save it again to regenerate the HTML.',
						'power-course'
					)}
				</Typography.Text>
			)}
			{isRepairable && (
				<div className="grid grid-cols-2 gap-2 max-h-60 overflow-y-auto">
					<span className="text-xs text-gray-500">
						{__('Before repair', 'power-course')}
					</span>
					<span className="text-xs text-gray-500">
						{__('After repair', 'power-course')}
					</span>
					{changes.map(({ before, after }, index) => (
						<Fragment key={index}>
							<pre className={CHANGE_CLASS_NAME}>{before}</pre>
							<pre className={CHANGE_CLASS_NAME}>{after}</pre>
						</Fragment>
					))}
				</div>
			)}
			{EMAIL_JSON_HEALTH_STATE.UNREPAIRABLE === state && (
				<Typography.Text type="danger">
					{__(
						'This email cannot be repaired automatically. No data was changed. Please contact technical support.',
						'power-course'
					)}
				</Typography.Text>
			)}
		</Card>
	)
}

export default Item
