import { useCreate, useUpdate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Drawer, Form, Input, Radio, DatePicker, Button, message } from 'antd'
import dayjs, { Dayjs } from 'dayjs'
import React, { useEffect, useMemo } from 'react'

import { TAnnouncement, TAnnouncementFormValues } from './types'

const { Item } = Form
const { TextArea } = Input

/**
 * 公告表單意圖型別。
 * - publish：發佈（後端依 post_date 自動 normalize 為 publish 或 future）
 * - draft：儲存為草稿
 */
type TAnnouncementIntent = 'publish' | 'draft'

type TFormFields = {
	post_title: string
	post_content?: string
	/**
	 * 使用者意圖。實際後端 post_status 由 normalize_status_and_date 推斷：
	 * publish + post_date 在未來 → future；publish + post_date 在過去/現在 → publish；
	 * draft → 維持 draft。
	 */
	post_status: TAnnouncementIntent
	post_date?: Dayjs
	end_at?: Dayjs | null
	visibility: 'public' | 'enrolled'
}

type TProps = {
	open: boolean
	onClose: () => void
	courseId: number
	record: TAnnouncement | null
	onSaved: () => void
}

export const AnnouncementForm = ({
	open,
	onClose,
	courseId,
	record,
	onSaved,
}: TProps) => {
	const [form] = Form.useForm<TFormFields>()
	const isEdit = Boolean(record?.id)

	const { mutate: doCreate, isLoading: creating } = useCreate()
	const { mutate: doUpdate, isLoading: updating } = useUpdate()
	const submitting = creating || updating

	// 監聽 post_date 變化，讓 end_at 的 disabledDate/disabledTime 邊界即時響應
	const watchedPostDate = Form.useWatch('post_date', form)

	const initialValues = useMemo<TFormFields>(() => {
		if (!record) {
			// 新建公告預設為「發佈意圖」（post_date = 現在 → 後端 normalize 為 publish）
			return {
				post_title: '',
				post_content: '',
				post_status: 'publish',
				post_date: dayjs(),
				end_at: null,
				visibility: 'public',
			}
		}
		// 編輯既有公告：草稿維持草稿；future 視為 publish 意圖（後端會 normalize 回 future）
		return {
			post_title: record.post_title,
			post_content: record.post_content,
			post_status: record.post_status === 'draft' ? 'draft' : 'publish',
			post_date: record.post_date ? dayjs(record.post_date) : dayjs(),
			end_at:
				typeof record.end_at === 'number' && record.end_at > 0
					? dayjs.unix(record.end_at)
					: null,
			visibility: record.visibility ?? 'public',
		}
	}, [record])

	useEffect(() => {
		if (open) {
			form.resetFields()
			form.setFieldsValue(initialValues)
		}
	}, [open, initialValues, form])

	/**
	 * end_at 的下限：max(今天, post_date)。end_at 不可早於發佈起始時間，也不可早於今天。
	 */
	const endAtBoundary = useMemo<Dayjs>(() => {
		const now = dayjs()
		if (watchedPostDate && dayjs.isDayjs(watchedPostDate)) {
			return watchedPostDate.isAfter(now) ? watchedPostDate : now
		}
		return now
	}, [watchedPostDate])

	/**
	 * 禁用早於 boundary（以「日」為單位）的日期。
	 */
	const disabledEndAtDate = (current: Dayjs | null): boolean => {
		if (!current) return false
		return current.isBefore(endAtBoundary, 'day')
	}

	/**
	 * 同一天時，禁用早於 boundary 的小時與分鐘。
	 */
	const disabledEndAtTime = (current: Dayjs | null) => {
		if (!current || !current.isSame(endAtBoundary, 'day')) {
			return {}
		}
		const boundaryHour = endAtBoundary.hour()
		const boundaryMinute = endAtBoundary.minute()
		return {
			disabledHours: () =>
				Array.from({ length: 24 }, (_, i) => i).filter((h) => h < boundaryHour),
			disabledMinutes: (selectedHour: number) => {
				if (selectedHour === boundaryHour) {
					return Array.from({ length: 60 }, (_, i) => i).filter(
						(m) => m < boundaryMinute
					)
				}
				return []
			},
		}
	}

	/**
	 * 提交表單。intent 直接作為 post_status 送出，後端會：
	 * - intent=publish 且 post_date 在未來 → 自動設為 future
	 * - intent=publish 且 post_date 在過去/現在 → 維持 publish
	 * - intent=draft → 維持 draft（end_at / post_date 由後端 early return 保留）
	 */
	const handleSubmit = async (intent: TAnnouncementIntent) => {
		try {
			const values = await form.validateFields()
			const payload: TAnnouncementFormValues & {
				parent_course_id?: number
			} = {
				post_title: values.post_title,
				post_content: values.post_content ?? '',
				post_status: intent,
				visibility: values.visibility,
				post_date: values.post_date
					? values.post_date.format('YYYY-MM-DD HH:mm:00')
					: undefined,
				end_at: values.end_at ? values.end_at.unix() : '',
			}

			if (isEdit && record) {
				doUpdate(
					{
						resource: 'announcements',
						id: record.id,
						values: payload,
						dataProviderName: 'power-course',
						successNotification: false,
					},
					{
						onSuccess: () => {
							message.success(__('Announcement updated', 'power-course'))
							onSaved()
							onClose()
						},
						onError: (err) => {
							message.error(
								err?.message ||
									__('Failed to update announcement', 'power-course')
							)
						},
					}
				)
			} else {
				doCreate(
					{
						resource: 'announcements',
						values: { ...payload, parent_course_id: courseId },
						dataProviderName: 'power-course',
						successNotification: false,
					},
					{
						onSuccess: () => {
							message.success(__('Announcement created', 'power-course'))
							onSaved()
							onClose()
						},
						onError: (err) => {
							message.error(
								err?.message ||
									__('Failed to create announcement', 'power-course')
							)
						},
					}
				)
			}
		} catch {
			// Form validation failed; antd already shows inline errors
		}
	}

	return (
		<Drawer
			title={
				isEdit
					? __('Edit announcement', 'power-course')
					: __('Add announcement', 'power-course')
			}
			open={open}
			onClose={onClose}
			width={640}
			destroyOnClose
			footer={
				<div className="text-right">
					<Button onClick={onClose} className="mr-2">
						{__('Cancel', 'power-course')}
					</Button>
					<Button
						loading={submitting}
						onClick={() => handleSubmit('draft')}
						className="mr-2"
					>
						{__('Save as draft', 'power-course')}
					</Button>
					<Button
						type="primary"
						loading={submitting}
						onClick={() => handleSubmit('publish')}
					>
						{__('Publish', 'power-course')}
					</Button>
				</div>
			}
		>
			<Form<TFormFields>
				form={form}
				layout="vertical"
				initialValues={initialValues}
			>
				<Item
					label={__('Announcement title', 'power-course')}
					name="post_title"
					rules={[
						{
							required: true,
							message: __('Please enter announcement title', 'power-course'),
						},
					]}
				>
					<Input placeholder={__('Announcement title', 'power-course')} />
				</Item>

				<Item
					label={__('Announcement content', 'power-course')}
					name="post_content"
					tooltip={__(
						'HTML is supported. Power Editor integration is planned for a future iteration.',
						'power-course'
					)}
				>
					<TextArea rows={8} />
				</Item>

				<Item
					label={__('Publish start time', 'power-course')}
					name="post_date"
					tooltip={__(
						'Site time zone is used. Future date triggers scheduled publish.',
						'power-course'
					)}
				>
					{/* post_date 不加 disabledDate：編輯舊公告時 post_date 可能在過去，加了會卡死表單 */}
					<DatePicker
						showTime={{ format: 'HH:mm' }}
						format="YYYY-MM-DD HH:mm"
						className="w-full"
					/>
				</Item>

				<Item
					label={__('Publish end time', 'power-course')}
					name="end_at"
					tooltip={__(
						'Leave empty for permanent display. Must be later than the start time.',
						'power-course'
					)}
				>
					<DatePicker
						showTime={{ format: 'HH:mm' }}
						format="YYYY-MM-DD HH:mm"
						className="w-full"
						disabledDate={disabledEndAtDate}
						disabledTime={disabledEndAtTime}
					/>
				</Item>

				<Item
					label={__('Visibility', 'power-course')}
					name="visibility"
					rules={[{ required: true }]}
				>
					<Radio.Group>
						<Radio value="public">
							{__('Public (everyone)', 'power-course')}
						</Radio>
						<Radio value="enrolled">
							{__('Enrolled students only', 'power-course')}
						</Radio>
					</Radio.Group>
				</Item>
			</Form>
		</Drawer>
	)
}
