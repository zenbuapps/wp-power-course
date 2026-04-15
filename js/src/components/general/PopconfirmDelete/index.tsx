import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import {
	Button,
	Popconfirm,
	PopconfirmProps,
	ButtonProps,
	Tooltip,
	TooltipProps,
} from 'antd'
import React, { FC } from 'react'

type PopconfirmDeleteProps = {
	popconfirmProps: Omit<PopconfirmProps, 'title'> & { title?: React.ReactNode }
	type?: 'icon' | 'button'
	buttonProps?: ButtonProps
	tooltipProps?: TooltipProps
}

export const PopconfirmDelete: FC<PopconfirmDeleteProps> = ({
	popconfirmProps,
	type = 'icon',
	buttonProps,
	tooltipProps,
}) => {
	const defaultProps: PopconfirmProps = {
		title: __('Confirm delete?', 'power-course'),
		okText: __('Confirm', 'power-course'),
		cancelText: __('Cancel', 'power-course'),
	}

	const mergedPopconfirmProps: PopconfirmProps = {
		...defaultProps,
		...popconfirmProps,
	}

	if (tooltipProps) {
		return (
			<Popconfirm {...mergedPopconfirmProps}>
				{'icon' === type && (
					<Tooltip {...tooltipProps}>
						<Button
							danger
							type="text"
							icon={<DeleteOutlined />}
							{...buttonProps}
						/>
					</Tooltip>
				)}

				{'button' === type && (
					<Button type="primary" danger {...buttonProps}>
						{buttonProps?.children ?? __('Delete', 'power-course')}
					</Button>
				)}
			</Popconfirm>
		)
	}

	return (
		<Popconfirm {...mergedPopconfirmProps}>
			{'icon' === type && (
				<Button danger type="text" icon={<DeleteOutlined />} {...buttonProps} />
			)}

			{'button' === type && (
				<Button type="primary" danger {...buttonProps}>
					{buttonProps?.children ?? __('Delete', 'power-course')}
				</Button>
			)}
		</Popconfirm>
	)
}
