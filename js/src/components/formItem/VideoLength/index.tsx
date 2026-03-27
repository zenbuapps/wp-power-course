import { FormItemProps, Form, Space, InputNumber } from 'antd'
import { FC, useState, useEffect } from 'react'

const { Item } = Form

export const VideoLength: FC<FormItemProps> = (formItemProps) => {
	const form = Form.useFormInstance()
	const [length, setLength] = useState({
		hour: 0,
		minute: 0,
		second: 0,
	})

	const { name } = formItemProps
	const recordId = Form.useWatch(['id'], form)

	const handleChange =
		(field: 'hour' | 'minute' | 'second') => (value: number | null) => {
			setLength((prev) => {
				const newLength = { ...prev, [field]: value ?? 0 }

				const newLengthInSecond =
					newLength.hour * 3600 + newLength.minute * 60 + newLength.second
				form.setFieldValue(name, newLengthInSecond)

				return newLength
			})
		}

	useEffect(() => {
		if (recordId) {
			const lengthInSecond = form.getFieldValue(name)
			const h = lengthInSecond ? Math.floor(lengthInSecond / 3600) : 0
			const m = lengthInSecond
				? Math.floor((lengthInSecond - h * 3600) / 60)
				: 0
			const s = lengthInSecond ? lengthInSecond - h * 3600 - m * 60 : 0
			setLength({ hour: h, minute: m, second: s })
		}
	}, [recordId])

	return (
		<>
			<Space.Compact block>
				<InputNumber
					addonAfter="時"
					value={length.hour}
					min={0}
					onChange={handleChange('hour')}
				/>
				<InputNumber
					addonAfter="分"
					value={length.minute}
					min={0}
					max={59}
					onChange={handleChange('minute')}
				/>
				<InputNumber
					addonAfter="秒"
					value={length.second}
					min={0}
					max={59}
					onChange={handleChange('second')}
				/>
			</Space.Compact>
			<p className="text-gray-400 m-0 text-sm">
				如果長度為 0 ，在前端不會顯示時長
			</p>

			<Item hidden {...formItemProps} />
		</>
	)
}
