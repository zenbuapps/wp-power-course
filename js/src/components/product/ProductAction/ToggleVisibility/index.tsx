import { EyeOutlined, EyeInvisibleOutlined } from '@ant-design/icons'
import { useUpdate } from '@refinedev/core'
import { Tooltip, Button } from 'antd'
import { toFormData } from 'antd-toolkit'
import React, { FC } from 'react'

import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'

const ToggleVisibility: FC<{
	record: TCourseBaseRecord
}> = ({ record }) => {
	const { catalog_visibility = 'visible', id } = record
	const isVisible = catalog_visibility !== 'hidden'

	const { mutate: update, isLoading } = useUpdate()

	const handleToggle = () => {
		const formData = toFormData({
			catalog_visibility: isVisible ? 'hidden' : 'visible',
		})
		update({
			resource: 'courses',
			dataProviderName: 'power-course',
			values: formData,
			id,
			meta: {
				headers: { 'Content-Type': 'multipart/form-data;' },
			},
		})
	}

	return (
		<Tooltip
			title={`調整商品型錄可見度隱藏，目前為${isVisible ? '可見' : '隱藏'}`}
		>
			{isVisible ? (
				<Button
					loading={isLoading}
					type="text"
					icon={<EyeOutlined className="text-gray-400" />}
					onClick={handleToggle}
				/>
			) : (
				<Button
					loading={isLoading}
					type="text"
					icon={<EyeInvisibleOutlined className="text-yellow-700" />}
					onClick={handleToggle}
				/>
			)}
		</Tooltip>
	)
}

export default ToggleVisibility
