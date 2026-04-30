import { __ } from '@wordpress/i18n'
import { Drawer, Form, Input, Radio, DatePicker, Button, message } from 'antd'
import { useCreate, useUpdate } from '@refinedev/core'
import dayjs, { Dayjs } from 'dayjs'
import React, { useEffect, useMemo } from 'react'

import { TAnnouncement, TAnnouncementFormValues } from './types'

const { Item } = Form
const { TextArea } = Input

type TFormFields = {
	post_title: string
	post_content?: string
	post_status: 'publish' | 'future'
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

	const initialValues = useMemo<TFormFields>(() => {
		if (!record) {
			return {
				post_title: '',
				post_content: '',
				post_status: 'publish',
				post_date: dayjs(),
				end_at: null,
				visibility: 'public',
			}
		}
		return {
			post_title: record.post_title,
			post_content: record.post_content,
			post_status:
				record.post_status === 'future'
					? 'future'
					: ('publish' as 'publish' | 'future'),
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

	const handleSubmit = async () => {
		try {
			const values = await form.validateFields()
			const payload: TAnnouncementFormValues & {
				parent_course_id?: number
			} = {
				post_title: values.post_title,
				post_content: values.post_content ?? '',
				post_status: values.post_status,
				visibility: values.visibility,
				post_date: values.post_date
					? values.post_date.format('YYYY-MM-DD HH:mm:ss')
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
							message.error(err?.message || __('Failed to update announcement', 'power-course'))
						},
					},
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
							message.error(err?.message || __('Failed to create announcement', 'power-course'))
						},
					},
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
					<Button type="primary" loading={submitting} onClick={handleSubmit}>
						{__('Save', 'power-course')}
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
						'power-course',
					)}
				>
					<TextArea rows={8} />
				</Item>

				<Item
					label={__('Publish status', 'power-course')}
					name="post_status"
					rules={[{ required: true }]}
				>
					<Radio.Group>
						<Radio value="publish">
							{__('Publish immediately', 'power-course')}
						</Radio>
						<Radio value="future">
							{__('Schedule', 'power-course')}
						</Radio>
					</Radio.Group>
				</Item>

				<Item
					label={__('Publish start time', 'power-course')}
					name="post_date"
					tooltip={__(
						'Site time zone is used. Future date triggers scheduled publish.',
						'power-course',
					)}
				>
					<DatePicker
						showTime
						format="YYYY-MM-DD HH:mm:ss"
						className="w-full"
					/>
				</Item>

				<Item
					label={__('Publish end time', 'power-course')}
					name="end_at"
					tooltip={__(
						'Leave empty for permanent display. Must be later than the start time.',
						'power-course',
					)}
				>
					<DatePicker
						showTime
						format="YYYY-MM-DD HH:mm:ss"
						className="w-full"
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
