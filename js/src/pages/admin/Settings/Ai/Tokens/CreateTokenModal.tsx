import { __ } from '@wordpress/i18n'
import { Form, Input, Modal, Select } from 'antd'
import { memo, useCallback, useEffect } from 'react'

import { TMcpTokenCreateResponse, TMcpTokenExpiresOption } from '@/types/mcp'

import { useCreateMcpToken } from '../hooks/useMcpTokens'

type TFormValues = {
	name: string
	expires: TMcpTokenExpiresOption
}

type TCreateTokenModalProps = {
	open: boolean
	/** 關閉 modal */
	onClose: () => void
	/** 建立成功後將明文 token 傳給父層顯示 */
	onCreated: (response: TMcpTokenCreateResponse) => void
}

/**
 * 建立新 MCP Token 的 Modal（Issue #230）
 *
 * 有效期限預設「永不過期」（Q1），不開放逐 Token 權限（Q2）。
 */
const CreateTokenModalComponent = ({
	open,
	onClose,
	onCreated,
}: TCreateTokenModalProps) => {
	const [form] = Form.useForm<TFormValues>()
	const { create, isLoading } = useCreateMcpToken()

	useEffect(() => {
		if (open) {
			form.resetFields()
		}
	}, [open, form])

	const handleOk = useCallback(() => {
		form
			.validateFields()
			.then(({ name, expires }) => {
				const values =
					expires === 'never'
						? { name }
						: { name, expires_days: Number(expires) }
				create(values, (response) => {
					onCreated(response)
				})
			})
			.catch(() => {
				// antd 會自動高亮欄位，無需額外處理
			})
	}, [form, create, onCreated])

	return (
		<Modal
			open={open}
			title={__('Create MCP token', 'power-course')}
			onOk={handleOk}
			onCancel={onClose}
			centered
			okText={__('Create', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			confirmLoading={isLoading}
			destroyOnClose
		>
			<Form form={form} layout="vertical" preserve={false}>
				<Form.Item
					name="name"
					label={__('Token name', 'power-course')}
					rules={[
						{
							required: true,
							message: __('Token name is required', 'power-course'),
						},
						{
							max: 100,
							message: __(
								'Name must not exceed 100 characters',
								'power-course'
							),
						},
					]}
				>
					<Input
						placeholder={__('e.g. Claude Code — my laptop', 'power-course')}
						allowClear
					/>
				</Form.Item>

				<Form.Item
					name="expires"
					label={__('Expiration', 'power-course')}
					initialValue="never"
					extra={__(
						'A token that never expires stays valid until you revoke it.',
						'power-course'
					)}
				>
					<Select
						options={[
							{ value: '30', label: __('30 days', 'power-course') },
							{ value: '90', label: __('90 days', 'power-course') },
							{ value: '365', label: __('1 year', 'power-course') },
							{ value: 'never', label: __('Never expires', 'power-course') },
						]}
					/>
				</Form.Item>
			</Form>
		</Modal>
	)
}

export const CreateTokenModal = memo(CreateTokenModalComponent)
