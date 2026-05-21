import { UndoOutlined, SearchOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import {
	FormProps,
	Form,
	Input,
	Button,
	FormInstance,
	Select,
	InputNumber,
} from 'antd'
import { memo } from 'react'

/**
 * 課程學員 Tab Filter
 *
 * Issue #227
 * - 欄位 1：關鍵字搜尋（單一 Input）
 * - 欄位 2：課程進度（Select 運算子 + InputNumber 0-100）
 *
 * 兩欄位必須同時有值；前端用 Form.Item dependencies 驗證，
 * 後端則由 Api 層做白名單 / 範圍 / 成對檢查（雙重把關）。
 */
export type TStudentFilterValues = {
	search?: string
	progress_operator?: '=' | '!=' | '<' | '<=' | '>' | '>='
	progress_value?: number
}

const { Item } = Form

const operatorOptions: {
	value: NonNullable<TStudentFilterValues['progress_operator']>
	label: string
}[] = [
	{ value: '=', label: __('Equal to', 'power-course') },
	{ value: '!=', label: __('Not equal to', 'power-course') },
	{ value: '<', label: __('Less than', 'power-course') },
	{ value: '<=', label: __('Less or equal', 'power-course') },
	{ value: '>', label: __('Greater than', 'power-course') },
	{ value: '>=', label: __('Greater or equal', 'power-course') },
]

const Filter = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps?.form as FormInstance<TStudentFilterValues>

	return (
		<div className="mb-2">
			<Form {...formProps} layout="vertical">
				<div className="grid grid-cols-1 md:grid-cols-3 gap-x-4">
					<Item name="search" label={__('Keyword search', 'power-course')}>
						<Input
							placeholder={__(
								'Enter user ID, username, email or display name',
								'power-course'
							)}
							allowClear
						/>
					</Item>

					<Item
						label={__('Progress', 'power-course')}
						className="md:col-span-2"
						required={false}
					>
						<Input.Group compact>
							<Item
								name="progress_operator"
								noStyle
								dependencies={['progress_value']}
								rules={[
									({ getFieldValue }) => ({
										validator(_, value) {
											const valueField = getFieldValue('progress_value')
											const hasValue =
												valueField !== undefined &&
												valueField !== null &&
												valueField !== ''
											if (hasValue && !value) {
												return Promise.reject(
													new Error(
														__(
															'Both operator and value are required',
															'power-course'
														)
													)
												)
											}
											return Promise.resolve()
										},
									}),
								]}
							>
								<Select
									placeholder={__('Operator', 'power-course')}
									options={operatorOptions}
									allowClear
									style={{ width: 140 }}
								/>
							</Item>

							<Item
								name="progress_value"
								noStyle
								dependencies={['progress_operator']}
								rules={[
									({ getFieldValue }) => ({
										validator(_, value) {
											const opField = getFieldValue('progress_operator')
											const hasOp = !!opField
											const hasValue =
												value !== undefined && value !== null && value !== ''
											if (hasOp && !hasValue) {
												return Promise.reject(
													new Error(
														__(
															'Both operator and value are required',
															'power-course'
														)
													)
												)
											}
											if (
												hasValue &&
												(typeof value !== 'number' ||
													!Number.isInteger(value) ||
													value < 0 ||
													value > 100)
											) {
												return Promise.reject(
													new Error(__('0-100', 'power-course'))
												)
											}
											return Promise.resolve()
										},
									}),
								]}
							>
								<InputNumber
									min={0}
									max={100}
									step={1}
									precision={0}
									addonAfter="%"
									placeholder={__('0-100', 'power-course')}
									style={{ width: 140 }}
								/>
							</Item>
						</Input.Group>
					</Item>
				</div>

				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4 mt-2">
					<Button
						htmlType="submit"
						type="primary"
						className="w-full"
						icon={<SearchOutlined />}
					>
						{__('Filter', 'power-course')}
					</Button>
					<Button
						type="default"
						className="w-full"
						onClick={() => {
							form.resetFields()
							form.submit()
						}}
						icon={<UndoOutlined />}
					>
						{__('Reset', 'power-course')}
					</Button>
				</div>
			</Form>
		</div>
	)
}

export default memo(Filter)
