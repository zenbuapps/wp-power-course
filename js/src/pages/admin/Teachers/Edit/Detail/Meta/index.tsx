import { __ } from '@wordpress/i18n'
import { Form, Input, Alert, Button } from 'antd'
import React, { useState, useEffect } from 'react'

import { useIsEditing, useRecord } from '../../hooks'

const { Item } = Form
const { TextArea } = Input

/**
 * 講師 Edit 頁 — Meta Tab（other_meta_data 動態編輯）
 *
 * 對齊 Power Shop Meta Tab：
 * - 列出 record.other_meta_data（來自 Powerhouse User::get_rest_meta_data()）
 * - 預設 read-only；需 **雙層 confirm** 才能編輯：
 *   1. 上層：isEditing（由 Edit 頁 footer「編輯用戶」按鈕觸發）
 *   2. 本層：isConfirm（需點擊 Alert 內「我很清楚我在做什麼」才啟用）
 * - 切換 isEditing 時自動 reset isConfirm
 * - meta_key / umeta_id 透過隱藏 Form.Item 收集，僅 meta_value 可編輯
 */
const Meta = () => {
	const isContextEditing = useIsEditing()
	const record = useRecord()
	const other_meta_data = record?.other_meta_data || []
	const [isConfirm, setIsConfirm] = useState(false)
	const isEditing = isContextEditing && isConfirm // 雙層 confirm

	useEffect(() => {
		setIsConfirm(false)
	}, [isContextEditing])

	return (
		<>
			{isContextEditing && (
				<Alert
					className="mb-4"
					message={__('Danger zone', 'power-course')}
					description={
						<>
							<p>
								{__(
									'This directly edits user_meta. If you are not sure, do not change anything.',
									'power-course'
								)}
							</p>
							{!isConfirm && (
								<div className="flex justify-start">
									<Button
										size="small"
										type="primary"
										danger
										onClick={() => setIsConfirm(true)}
									>
										{__('I know what I am doing', 'power-course')}
									</Button>
								</div>
							)}
						</>
					}
					type="error"
					showIcon
				/>
			)}

			<div className="grid grid-cols-1 gap-8">
				<table className="table table-vertical table-sm text-xs [&_th]:!w-52 [&_td]:break-all [&_th]:break-all">
					<tbody>
						{other_meta_data.map(
							({ umeta_id, meta_key, meta_value }, index) => (
								<tr key={umeta_id}>
									<th className="text-left">{meta_key}</th>
									<td className="gap-x-1">
										{!isEditing && meta_value}
										{isEditing && (
											<>
												<Item
													name={['other_meta_data', index, 'umeta_id']}
													hidden
												/>
												<Item
													name={['other_meta_data', index, 'meta_key']}
													hidden
												/>
												<Item
													name={['other_meta_data', index, 'meta_value']}
													noStyle
												>
													<TextArea rows={1} className="text-xs" />
												</Item>
											</>
										)}
									</td>
								</tr>
							)
						)}
					</tbody>
				</table>
			</div>
		</>
	)
}

export default Meta
