import { DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import {
	Button,
	Card,
	Form,
	Input,
	InputNumber,
	Select,
	Space,
	Alert,
} from 'antd'
import { memo } from 'react'

import { DatePicker } from '@/components/formItem'
import { useCourseSelect } from '@/hooks/useCourseSelect'

type TLimitType = 'unlimited' | 'fixed' | 'assigned'

type TAutoGrantRowProps = {
	field: {
		name: number
		key: number
	}
	onRemove: (index: number) => void
}

const LIMIT_TYPE_OPTIONS: { label: string; value: TLimitType }[] = [
	{ label: '無期限', value: 'unlimited' },
	{ label: '固定期限', value: 'fixed' },
	{ label: '指定到期日', value: 'assigned' },
]

const LIMIT_UNIT_OPTIONS = [
	{ label: '日', value: 'day' },
	{ label: '月', value: 'month' },
	{ label: '年', value: 'year' },
]

const AutoGrantRow = ({ field, onRemove }: TAutoGrantRowProps) => {
	const form = Form.useFormInstance()
	const limitType: TLimitType | undefined = Form.useWatch(
		['auto_grant_courses', field.name, 'limit_type'],
		form
	)

	const { selectProps } = useCourseSelect({ selectProps: { mode: undefined } })
	const courseSelectProps = (({ value, onChange, ...rest }) => rest)(
		selectProps
	)

	const handleLimitTypeChange = (value: TLimitType) => {
		const basePath = ['auto_grant_courses', field.name]
		if ('unlimited' === value) {
			form.setFieldValue([...basePath, 'limit_value'], '')
			form.setFieldValue([...basePath, 'limit_unit'], '')
		}
		if ('fixed' === value) {
			form.setFieldValue([...basePath, 'limit_value'], 1)
			form.setFieldValue([...basePath, 'limit_unit'], 'day')
		}
		if ('assigned' === value) {
			form.setFieldValue([...basePath, 'limit_value'], undefined)
			form.setFieldValue([...basePath, 'limit_unit'], 'timestamp')
		}
	}

	return (
		<Card key={field.key} className="mb-4">
			<div className="flex items-start justify-between gap-4">
				<div className="flex-1">
					<Form.Item
						label="課程"
						name={[field.name, 'course_id']}
						rules={[{ required: true, message: '請選擇課程' }]}
					>
						<Select
							{...courseSelectProps}
							allowClear
							placeholder="搜尋課程關鍵字"
						/>
					</Form.Item>

					<Form.Item
						label="觀看期限"
						name={[field.name, 'limit_type']}
						initialValue="unlimited"
					>
						<Select
							options={LIMIT_TYPE_OPTIONS}
							onChange={handleLimitTypeChange}
						/>
					</Form.Item>

					{'fixed' === limitType && (
						<Space.Compact block>
							<Form.Item
								name={[field.name, 'limit_value']}
								className="w-full"
								rules={[{ required: true, message: '請輸入期限數值' }]}
								initialValue={1}
							>
								<InputNumber className="w-full" min={1} />
							</Form.Item>
							<Form.Item
								name={[field.name, 'limit_unit']}
								initialValue="day"
								rules={[{ required: true, message: '請選擇期限單位' }]}
							>
								<Select options={LIMIT_UNIT_OPTIONS} className="w-20" />
							</Form.Item>
						</Space.Compact>
					)}

					{'assigned' === limitType && (
						<>
							<DatePicker
								formItemProps={{
									name: [field.name, 'limit_value'],
									label: '到期日',
									rules: [{ required: true, message: '請選擇到期日' }],
								}}
							/>
							<Form.Item
								name={[field.name, 'limit_unit']}
								initialValue="timestamp"
								hidden
							>
								<Input />
							</Form.Item>
						</>
					)}

					{'unlimited' === limitType && (
						<>
							<Form.Item
								name={[field.name, 'limit_value']}
								initialValue=""
								hidden
							/>
							<Form.Item
								name={[field.name, 'limit_unit']}
								initialValue=""
								hidden
							/>
						</>
					)}
				</div>

				<Button
					danger
					type="text"
					icon={<DeleteOutlined />}
					onClick={() => onRemove(field.name)}
				>
					移除
				</Button>
			</div>
		</Card>
	)
}

const AutoGrant = () => {
	return (
		<div className="w-full max-w-[400px]">
			<Alert
				className="mb-4"
				message="用戶註冊後，可以自動獲的課程權限"
				type="info"
				showIcon
			/>
			<Form.List name={['auto_grant_courses']}>
				{(fields, { add, remove }) => (
					<>
						{fields.map((field) => (
							<AutoGrantRow key={field.key} field={field} onRemove={remove} />
						))}
						<Button
							type="dashed"
							icon={<PlusOutlined />}
							onClick={() =>
								add({
									limit_type: 'unlimited',
									limit_value: '',
									limit_unit: '',
								})
							}
						>
							新增課程
						</Button>
					</>
				)}
			</Form.List>
		</div>
	)
}

export default memo(AutoGrant)
