import { DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
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

const getLimitTypeOptions = (): { label: string; value: TLimitType }[] => [
	{ label: __('Unlimited', 'power-course'), value: 'unlimited' },
	{ label: __('Fixed period', 'power-course'), value: 'fixed' },
	{ label: __('Specific expiration date', 'power-course'), value: 'assigned' },
]

const getLimitUnitOptions = () => [
	{ label: __('Day', 'power-course'), value: 'day' },
	{ label: __('Month', 'power-course'), value: 'month' },
	{ label: __('Year', 'power-course'), value: 'year' },
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
						label={__('Course', 'power-course')}
						name={[field.name, 'course_id']}
						rules={[
							{
								required: true,
								message: __('Please select a course', 'power-course'),
							},
						]}
					>
						<Select
							{...courseSelectProps}
							allowClear
							placeholder={__('Search course keywords', 'power-course')}
						/>
					</Form.Item>

					<Form.Item
						label={__('Access period', 'power-course')}
						name={[field.name, 'limit_type']}
						initialValue="unlimited"
					>
						<Select
							options={getLimitTypeOptions()}
							onChange={handleLimitTypeChange}
						/>
					</Form.Item>

					{'fixed' === limitType && (
						<Space.Compact block>
							<Form.Item
								name={[field.name, 'limit_value']}
								className="w-full"
								rules={[
									{
										required: true,
										message: __('Please enter period value', 'power-course'),
									},
								]}
								initialValue={1}
							>
								<InputNumber className="w-full" min={1} />
							</Form.Item>
							<Form.Item
								name={[field.name, 'limit_unit']}
								initialValue="day"
								rules={[
									{
										required: true,
										message: __('Please select period unit', 'power-course'),
									},
								]}
							>
								<Select options={getLimitUnitOptions()} className="w-20" />
							</Form.Item>
						</Space.Compact>
					)}

					{'assigned' === limitType && (
						<>
							<DatePicker
								formItemProps={{
									name: [field.name, 'limit_value'],
									label: __('Expiration date', 'power-course'),
									rules: [
										{
											required: true,
											message: __(
												'Please select expiration date',
												'power-course'
											),
										},
									],
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
					{__('Remove', 'power-course')}
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
				message={__(
					'Automatically grant course access when users register',
					'power-course'
				)}
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
							{__('Add course', 'power-course')}
						</Button>
					</>
				)}
			</Form.List>
		</div>
	)
}

export default memo(AutoGrant)
