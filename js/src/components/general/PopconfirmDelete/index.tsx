import { DeleteOutlined } from '@ant-design/icons'
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

const DEFAULT_PROPS: PopconfirmProps = {
	title: '確認刪除嗎?',
	okText: '確認',
	cancelText: '取消',
}

export const PopconfirmDelete: FC<PopconfirmDeleteProps> = ({
	popconfirmProps,
	type = 'icon',
	buttonProps,
	tooltipProps,
}) => {
	const mergedPopconfirmProps: PopconfirmProps = {
		...DEFAULT_PROPS,
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
						{buttonProps?.children ?? '刪除'}
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
					{buttonProps?.children ?? '刪除'}
				</Button>
			)}
		</Popconfirm>
	)
}
