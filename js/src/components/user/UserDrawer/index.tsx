import { __ } from '@wordpress/i18n'
import { Drawer, DrawerProps, Form, Input } from 'antd'
import { FC } from 'react'

import { UserAvatarUpload } from '@/components/user'

const { Item } = Form

export const UserDrawer: FC<DrawerProps> = (drawerProps) => {
	const form = Form.useFormInstance()
	const watchId = Form.useWatch(['id'], form)
	const isUpdate = !!watchId

	return (
		<>
			<Drawer {...drawerProps}>
				{/* 這邊這個 form 只是為了調整 style */}

				<Form layout="vertical" form={form}>
					<Item name={['id']} hidden>
						<Input />
					</Item>
					<UserAvatarUpload />

					<Item
						name={['user_login']}
						label={__('Username', 'power-course')}
						rules={[
							{
								required: true,
								message: __('Username is required', 'power-course'),
							},
						]}
					>
						<Input disabled={isUpdate} />
					</Item>
					<Item
						name={['user_pass']}
						label={__('Password', 'power-course')}
						initialValue={undefined}
						rules={[
							{
								required: !isUpdate,
								message: __('Password is required', 'power-course'),
							},
						]}
					>
						<Input.Password />
					</Item>
					<Item
						name={['user_email']}
						label="Email"
						rules={[
							{
								required: true,
								message: __('Email is required', 'power-course'),
							},
						]}
					>
						<Input disabled={isUpdate} />
					</Item>
					<Item
						name={['display_name']}
						label={__('Display name', 'power-course')}
					>
						<Input />
					</Item>
					<Item
						name={['description']}
						label={__('Instructor description', 'power-course')}
					>
						<Input.TextArea rows={8} allowClear />
					</Item>
				</Form>
			</Drawer>
		</>
	)
}
