import { DeleteButton } from '@refinedev/antd'
import { useCreate, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Badge, Button, Form, Input, Switch, Timeline } from 'antd'
import { cn, renderHTML } from 'antd-toolkit'
import dayjs from 'dayjs'
import { FC } from 'react'

import { TUserContactRemark, TUserDetail } from '@/components/user/types'

const { TextArea } = Input
const { Item } = Form

/** ContactRemarks props */
type TContactRemarksProps = {
	/** 學員詳細資料（含 contact_remarks 與 id） */
	record: TUserDetail
	/** 是否可刪除備註，預設 true */
	canDelete?: boolean
	/** 是否可新增備註，預設 true */
	canCreate?: boolean
}

/**
 * 聯絡註記元件
 *
 * 以 Timeline 依日期分組呈現備註，支援新增與刪除備註。
 * 所有 comments resource 的讀寫（useCreate / useInvalidate / DeleteButton）
 * 皆顯式指定 dataProviderName 'power-course'，路由到 power-course namespace。
 *
 * 註（Issue #238 B3/Q4=A）：「內部備註」Switch 可切換內部 / 客戶可見標記（badge 顏色隨之變化），
 * 預設為內部備註（is_customer_note=false）。客戶可見備註的前台呈現另開 Issue 處理。
 */
export const ContactRemarks: FC<TContactRemarksProps> = ({
	record,
	canDelete = true,
	canCreate = true,
}) => {
	const [form] = Form.useForm()
	const invalidate = useInvalidate()
	const { mutate: create, isLoading } = useCreate({
		resource: 'comments',
		dataProviderName: 'power-course',
	})
	const groupedItems = groupItemsByDate(record?.contact_remarks)
	const items = Object.keys(groupedItems || {})?.map((date) => {
		const comments = groupedItems[date]
		return {
			key: date,
			children: comments?.map(
				({ content, date_created, customer_note, added_by, id: commentId }) => (
					<Badge.Ribbon
						key={commentId}
						className="-top-2"
						text={
							customer_note
								? __('Customer note', 'power-course')
								: __('Internal note', 'power-course')
						}
						color={customer_note ? 'yellow' : 'blue'}
					>
						<div
							className={cn(
								'p-4 relative mb-4',
								customer_note ? 'bg-yellow-50' : 'bg-blue-50'
							)}
						>
							{renderHTML(content)}
							<p className="text-xs text-gray-400 mb-0 flex items-center justify-between">
								{date_created}{' '}
								{sprintf(
									// translators: %s: 添加者名稱
									__('Added by %s', 'power-course'),
									added_by === 'system'
										? __('System', 'power-course')
										: added_by
								)}
								{canDelete && (
									<DeleteButton
										resource="comments"
										dataProviderName="power-course"
										recordItemId={commentId}
										type="link"
										size="small"
										hideText
										confirmTitle={__(
											'Confirm delete this note?',
											'power-course'
										)}
										confirmOkText={__('Confirm', 'power-course')}
										confirmCancelText={__('Cancel', 'power-course')}
										onSuccess={() => {
											invalidate({
												resource: 'users',
												dataProviderName: 'power-course',
												invalidates: ['detail'],
												id: record?.id,
											})
										}}
									/>
								)}
							</p>
						</div>
					</Badge.Ribbon>
				)
			),
			dot: (
				<p className="text-xs text-right mb-0 relative right-[1rem] text-gray-400">
					{date}
				</p>
			),
		}
	})
	const watchIsCustomerNote = Form.useWatch(['is_customer_note'], form)

	// 創建 comment
	const handleCreate = () => {
		const values = form.getFieldsValue()

		create(
			{
				values: {
					comment_type: 'contact_remark',
					commented_user_id: record?.id,
					note: values?.note,
					is_customer_note: values?.is_customer_note ? 1 : 0,
				},
			},
			{
				onSuccess: () => {
					form.resetFields()
					invalidate({
						resource: 'users',
						dataProviderName: 'power-course',
						invalidates: ['detail'],
						id: record?.id,
					})
				},
			}
		)
	}

	const formattedItems = canCreate
		? [
				{
					key: 'create',
					children: (
						<Form form={form} className="mb-8">
							<Item name={['note']} noStyle>
								<TextArea className="mb-2" rows={4} />
							</Item>
							<div className="flex justify-between items-center">
								<div className="flex items-center">
									<Item
										name={['is_customer_note']}
										initialValue={false}
										noStyle
									>
										<Switch size="small" />
									</Item>
									<span className="ml-2 text-sm text-gray-400">
										{watchIsCustomerNote
											? __(
													'Customer note, the customer will see this note',
													'power-course'
												)
											: __('Internal note', 'power-course')}
									</span>
								</div>
								<Button
									type="primary"
									loading={isLoading}
									onClick={handleCreate}
								>
									{__('Add', 'power-course')}
								</Button>
							</div>
						</Form>
					),
					dot: (
						<p className="text-xs text-right mb-0 relative right-[1rem] text-gray-400">
							{dayjs().format('YYYY-MM-DD')}
						</p>
					),
				},
				...items,
			]
		: items

	return (
		<div className="pl-10">
			<Timeline
				items={[
					...formattedItems,
					{
						key: 'empty',
						children: '',
						dot: <></>,
					},
				]}
			/>
		</div>
	)
}

/**
 * 按日期分組聯絡註記
 * @param items      含 date_created 的註記陣列
 * @param dateFormat 日期格式，預設 'YYYY-MM-DD'
 * @return 以日期為鍵的分組物件
 */
function groupItemsByDate(
	items: TUserDetail['contact_remarks'],
	dateFormat = 'YYYY-MM-DD'
) {
	const groupedItems = items?.reduce(
		(groups, item) => {
			const dateKey = dayjs(item?.date_created)?.format(dateFormat)

			if (!groups?.[dateKey]) {
				groups[dateKey] = []
			}

			groups?.[dateKey]?.push(item)

			return groups
		},
		{} as Record<string, TUserContactRemark[]>
	)

	return groupedItems
}
