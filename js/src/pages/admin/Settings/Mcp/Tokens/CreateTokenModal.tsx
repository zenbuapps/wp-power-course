import { Button, Checkbox, Form, Input, Modal } from 'antd'
import { memo, useCallback, useEffect } from 'react'

import { MCP_CATEGORIES, TMcpCategory } from '@/types/mcp'

import { useCreateMcpToken } from '../hooks/useMcpTokens'

type TFormValues = {
	name: string
	capabilities: TMcpCategory[]
}

type TCreateTokenModalProps = {
	open: boolean
	/** 關閉 modal */
	onClose: () => void
	/** 建立成功後將明文 token 傳給父層顯示 */
	onCreated: (plaintextToken: string, tokenName: string) => void
}

const ALL_CATEGORY_KEYS: TMcpCategory[] = MCP_CATEGORIES.map((c) => c.key)

/**
 * 建立新 MCP Token 的 Modal
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

	const handleSelectAll = useCallback(() => {
		form.setFieldValue('capabilities', ALL_CATEGORY_KEYS)
	}, [form])

	const handleClearAll = useCallback(() => {
		form.setFieldValue('capabilities', [])
	}, [form])

	const handleOk = useCallback(() => {
		form
			.validateFields()
			.then((values) => {
				create(values, (response) => {
					onCreated(response.plaintext_token, response.name)
				})
			})
			.catch(() => {
				// antd 會自動高亮欄位，無需額外處理
			})
	}, [form, create, onCreated])

	return (
		<Modal
			open={open}
			title="建立新 Token"
			onOk={handleOk}
			onCancel={onClose}
			okText="建立"
			cancelText="取消"
			confirmLoading={isLoading}
			destroyOnClose
		>
			<Form form={form} layout="vertical" preserve={false}>
				<Form.Item
					name="name"
					label="Token 名稱"
					rules={[
						{ required: true, message: '請輸入 Token 名稱' },
						{ max: 100, message: '名稱長度不可超過 100 字' },
					]}
				>
					<Input placeholder="例如：我的 AI 助理" allowClear />
				</Form.Item>

				<Form.Item
					name="capabilities"
					label="允許的 Capabilities"
					rules={[
						{
							required: true,
							message: '至少需選擇一個 category',
							type: 'array',
							min: 1,
						},
					]}
					initialValue={[]}
					extra={
						<div className="text-xs text-gray-500 mt-1">
							<Button
								type="link"
								size="small"
								className="!px-0 !mr-3"
								onClick={handleSelectAll}
							>
								全選
							</Button>
							<Button
								type="link"
								size="small"
								className="!px-0"
								onClick={handleClearAll}
							>
								全不選
							</Button>
						</div>
					}
				>
					<Checkbox.Group className="w-full">
						<div className="grid grid-cols-2 md:grid-cols-3 gap-2">
							{MCP_CATEGORIES.map((category) => (
								<Checkbox key={category.key} value={category.key}>
									{category.label}{' '}
									<span className="text-xs text-gray-400">
										({category.key})
									</span>
								</Checkbox>
							))}
						</div>
					</Checkbox.Group>
				</Form.Item>
			</Form>
		</Modal>
	)
}

export const CreateTokenModal = memo(CreateTokenModalComponent)
