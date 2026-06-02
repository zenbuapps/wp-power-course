import { __ } from '@wordpress/i18n'
import { Alert, Button, Form, Input } from 'antd'
import { useEffect, useState } from 'react'

import {
	useIsEditing,
	useRecord,
} from '@/components/user/StudentEditModal/hooks'

const { Item } = Form
const { TextArea } = Input

/**
 * 其他欄位（user_meta）區塊
 *
 * 直接操作 user_meta 屬危險操作，需兩層 confirm（編輯模式 + 點擊確認）才能編輯。
 * 每列 Form name 採 `['other_meta_data', index, ...]` 結構，
 * 儲存複用 POST users/{id} 對 other_meta_data[] 的支援。
 */
const Meta = () => {
	const isContextEditing = useIsEditing()
	const record = useRecord()
	const other_meta_data = record?.other_meta_data || []
	const [isConfirm, setIsConfirm] = useState(false)
	const isEditing = isContextEditing && isConfirm // 要兩層 confirm 才能編輯

	useEffect(() => {
		setIsConfirm(false)
	}, [isContextEditing])

	return (
		<>
			{isContextEditing && (
				<Alert
					className="mb-4"
					message={__('Dangerous operation', 'power-course')}
					description={
						<>
							<p>
								{__(
									'This directly operates on user_meta data. If you do not understand the implications, please do not make any changes.',
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
										{__('I understand the risk', 'power-course')}
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
						{other_meta_data?.map(
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
													hidden={!isEditing}
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
