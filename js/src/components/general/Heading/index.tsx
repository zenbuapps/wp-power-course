import { SendOutlined } from '@ant-design/icons'
import { Divider, Typography, TypographyProps, DividerProps } from 'antd'
import React, { FC, memo } from 'react'

const { Title } = Typography

/**
 * 必須 Omit 掉 DividerProps 的 size —— antd 5.20 起 DividerProps 新增了
 * `size?: SizeType`（'small' | 'middle' | 'large'），與本元件自訂的
 * `size?: 'sm' | 'md'` 在交集型別下字面量無交集，會收斂成 undefined，
 * 導致傳入任何 size 值都被判為不可指派（TS2322）。
 *
 * runtime 不受影響：size 在解構時已被取出，不會混進傳給 Divider 的 rest。
 */
const HeadingComponent: FC<
	{
		children: React.ReactNode
		titleProps?: TypographyProps['Title']
		size?: 'sm' | 'md'
	} & Omit<DividerProps, 'size'>
> = ({ children, titleProps, size = 'md', ...rest }) => {
	if (size === 'sm') {
		return (
			<Divider
				orientation="left"
				className="text-sm text-gray-400 my-8"
				plain
				orientationMargin="0"
				{...rest}
			>
				<SendOutlined className="mr-2" /> {children}
			</Divider>
		)
	}

	return (
		<Divider orientation="left" orientationMargin={0} plain {...rest}>
			<Title
				level={2}
				className="font-bold text-lg pl-2"
				style={{
					borderLeft: '4px solid #60a5fa',
					lineHeight: '1',
				}}
				{...titleProps}
			>
				{children}
			</Title>
		</Divider>
	)
}

export const Heading = memo(HeadingComponent)
