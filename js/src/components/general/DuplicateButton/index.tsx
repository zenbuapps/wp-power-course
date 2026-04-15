import { CopyOutlined } from '@ant-design/icons'
import {
	useCustomMutation,
	useApiUrl,
	useInvalidate,
	UseInvalidateProp,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Button, Tooltip, TooltipProps } from 'antd'
import { FC, memo } from 'react'

const DuplicateButtonComponent: FC<{
	id: string
	tooltipProps?: TooltipProps
	invalidateProps: Omit<UseInvalidateProp, 'invalidates'>
}> = ({ id, invalidateProps, tooltipProps }) => {
	const { mutate: duplicate, isLoading } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	const handleDuplicate = () => {
		duplicate(
			{
				url: `${apiUrl}/duplicate/${id}`,
				method: 'post',
				values: {},
			},
			{
				onSuccess: (data, variables, context) => {
					invalidate({
						dataProviderName: 'power-course',
						invalidates: ['list'],
						...invalidateProps,
					})
				},
			}
		)
	}

	return (
		<>
			<Tooltip title={__('Duplicate', 'power-course')} {...tooltipProps}>
				<Button
					type="text"
					className="text-gray-400"
					icon={<CopyOutlined />}
					onClick={handleDuplicate}
					loading={isLoading}
				/>
			</Tooltip>
		</>
	)
}

export const DuplicateButton = memo(DuplicateButtonComponent)
