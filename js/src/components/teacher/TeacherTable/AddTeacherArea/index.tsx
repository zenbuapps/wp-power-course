import { PlusOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Button, Form, FormInstance } from 'antd'
import React from 'react'

import { UserDrawer } from '@/components/user'
import { useUserFormDrawer } from '@/hooks'

export const AddTeacherArea = () => {
	const [form] = Form.useForm<FormInstance>()
	const { show, drawerProps } = useUserFormDrawer({
		form,
		resource: 'users',
	})

	return (
		<div className="flex gap-4 items-center mb-4">
			<Button type="primary" icon={<PlusOutlined />} onClick={show()}>
				{__('Create instructor', 'power-course')}
			</Button>

			<Form layout="vertical" form={form}>
				<UserDrawer {...drawerProps} />
			</Form>
		</div>
	)
}
