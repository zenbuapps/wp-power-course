/**
 * 批改操作（Issue #131 P7）
 *
 * 通過 / 未通過 / 改回待批改 + 評語，對應 `features/assignment/批改作業.feature`。
 * 「待批改」選項是刻意提供的：老師誤判後要能把繳交改回待批改重新處理。
 */
import { _x, __ } from '@wordpress/i18n'
import { Button, Input, Radio, type RadioChangeEvent } from 'antd'
import { memo, useEffect, useState } from 'react'

import {
	SUBMISSION_STATUS,
	type TGradeFormProps,
	type TSubmissionStatus,
} from '../types'

/**
 * 批改表單元件
 */
const GradeFormComponent = ({
	record,
	isGrading,
	onSubmit,
}: TGradeFormProps) => {
	const [status, setStatus] = useState<TSubmissionStatus>(record.status)
	const [feedback, setFeedback] = useState<string>(record.feedback ?? '')

	// 只在「換一筆繳交」時重置表單；record 物件本身會因列表重新取得而換 reference，
	// 若以 record 當依賴，老師打到一半的評語會在背景 refetch 後被清空。
	useEffect(() => {
		setStatus(record.status)
		setFeedback(record.feedback ?? '')
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [record.id])

	const options = [
		{
			value: SUBMISSION_STATUS.PASS,
			label: _x('Passed', 'assignment submission status', 'power-course'),
		},
		{
			value: SUBMISSION_STATUS.FAIL,
			label: _x('Failed', 'assignment submission status', 'power-course'),
		},
		{
			value: SUBMISSION_STATUS.PENDING,
			label: _x('Pending', 'assignment submission status', 'power-course'),
		},
	]

	return (
		<div>
			<div className="mb-2 font-semibold">
				{__('Grading result', 'power-course')}
			</div>

			<div className="pc-assignment-grade-radio mb-4">
				<Radio.Group
					value={status}
					onChange={(event: RadioChangeEvent) =>
						setStatus(event.target.value as TSubmissionStatus)
					}
				>
					{options.map((option) => (
						<Radio.Button key={option.value} value={option.value}>
							{option.label}
						</Radio.Button>
					))}
				</Radio.Group>
			</div>

			<div className="mb-2">{__('Feedback', 'power-course')}</div>
			<Input.TextArea
				id="feedback"
				rows={4}
				value={feedback}
				onChange={(event) => setFeedback(event.target.value)}
			/>

			<Button
				className="mt-4"
				type="primary"
				loading={isGrading}
				onClick={() => onSubmit({ status, feedback })}
			>
				{__('Save grading result', 'power-course')}
			</Button>
		</div>
	)
}

export const GradeForm = memo(GradeFormComponent)
