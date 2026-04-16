import { ArrowsAltOutlined, ExclamationCircleFilled } from '@ant-design/icons'
import { useModal } from '@refinedev/antd'
import { useCustomMutation, useApiUrl } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import {
	Form,
	Button,
	Modal,
	Space,
	DatePicker,
	Tooltip,
	DatePickerProps,
	message,
} from 'antd'
import dayjs, { Dayjs } from 'dayjs'
import { useAtom } from 'jotai'
import React, { useState } from 'react'

import {
	UserTable,
	selectedUserIdsAtom,
	SelectedUser,
} from '@/components/user/UserTable'

const disabledDate: DatePickerProps['disabledDate'] = (current) => {
	// Can not select days before today and today
	return current && current < dayjs().startOf('day')
}

const disabledDateTime = (data: Dayjs) => {
	const currentDay = dayjs().date()
	if (data.date() !== currentDay) {
		return {}
	}
	const currentHour = dayjs().hour()
	const disabledHoursArray = [...Array(currentHour).keys()]
	const currentMinute = dayjs().minute() + 2
	const disabledMinutesArray = [...Array(currentMinute).keys()]

	return {
		disabledHours: () => disabledHoursArray,
		disabledMinutes: () => disabledMinutesArray,
	}
}

const Specific = ({ email_ids }: { email_ids: string[] }) => {
	const [selectedUserIds, setSelectedUserIds] = useAtom(selectedUserIdsAtom)
	const [form] = Form.useForm()
	const { show, modalProps } = useModal()
	const watchType = Form.useWatch(['trigger', 'type'], form)

	const apiUrl = useApiUrl('power-email')
	const { mutate: SendEmail, isLoading } = useCustomMutation()
	const handleSendNow = () => {
		SendEmail(
			{
				url: `${apiUrl}/emails/send-now`,
				method: 'post',
				values: {
					email_ids,
					user_ids: selectedUserIds,
				},
			},
			{
				onSuccess: () => {
					message.success(__('Email sent successfully', 'power-course'))
				},
				onError: () => {
					message.error(__('Failed to send email', 'power-course'))
				},
			}
		)
	}

	// 排程
	const [time, setTime] = useState<Dayjs | null>(null)
	const handleSendSchedule = () => {
		SendEmail(
			{
				url: `${apiUrl}/emails/send-schedule`,
				method: 'post',
				values: {
					email_ids,
					user_ids: selectedUserIds,
					timestamp: time?.unix(), // 10位
				},
			},
			{
				onSuccess: () => {
					message.success(__('Email scheduled successfully', 'power-course'))
				},
				onError: () => {
					message.error(__('Failed to schedule email', 'power-course'))
				},
			}
		)
	}

	return (
		<>
			<Form form={form} layout="vertical" className="mb-8">
				<div className="w-full max-w-[20rem]">
					<div className="mb-4 flex gap-x-2 items-center">
						<Button
							onClick={show}
							icon={<ArrowsAltOutlined />}
							iconPosition="end"
						>
							{__('Select users to send', 'power-course')}
						</Button>
						<SelectedUser
							user_ids={selectedUserIds}
							onClear={() => setSelectedUserIds([])}
						/>
					</div>
					<div>
						<div className="flex gap-x-2 items-center">
							<Space.Compact>
								<DatePicker
									placeholder={__('Select send time', 'power-course')}
									value={time}
									showTime
									format="YYYY-MM-DD HH:mm"
									onChange={(value: Dayjs) => {
										setTime(value)
									}}
									className="w-[12rem]"
									disabledDate={disabledDate}
									disabledTime={disabledDateTime}
								/>
								<Button
									onClick={handleSendSchedule}
									disabled={!time || email_ids.length !== 1}
									loading={isLoading}
								>
									{__('Schedule send', 'power-course')}
								</Button>
							</Space.Compact>
							<Tooltip
								title={__(
									'Send time is approximate, not 100% accurate, depending on system load',
									'power-course'
								)}
							>
								<ExclamationCircleFilled className="text-red-500" />
							</Tooltip>
							<Button
								className="ml-4"
								type="primary"
								onClick={handleSendNow}
								loading={isLoading}
								disabled={email_ids.length !== 1 || !selectedUserIds.length}
							>
								{__('Send now', 'power-course')}
							</Button>
						</div>
					</div>
				</div>
			</Form>
			<Modal
				{...modalProps}
				title={__('Select users', 'power-course')}
				width={1600}
				footer={null}
				centered
				zIndex={2000}
			>
				<UserTable
					cardProps={{ showCard: false }}
					tableProps={{
						scroll: { y: 420 },
					}}
				/>
			</Modal>
		</>
	)
}

export default Specific
