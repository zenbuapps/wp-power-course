import {
	PlusOutlined,
	DeleteOutlined,
	ArrowUpOutlined,
	ArrowDownOutlined,
} from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Form, Button, Tooltip } from 'antd'
import { FC } from 'react'

import { VideoInput } from '../VideoInput'

const MAX_TRIAL_VIDEOS = 6

/**
 * TrialVideosList — Issue #10：多影片試看清單
 *
 * - 以 Form.List 管理 trial_videos 陣列
 * - 上限 6 部，達上限時新增按鈕 disabled 並顯示提示
 * - 每筆支援上移 / 下移 / 刪除
 * - VideoInput 不顯示字幕管理（多影片字幕為 v2 範圍，見 Issue #10 計畫）
 */
export const TrialVideosList: FC = () => {
	return (
		<Form.List name={['trial_videos']}>
			{(fields, { add, remove, move }) => {
				const reachedMax = fields.length >= MAX_TRIAL_VIDEOS

				return (
					<div className="flex flex-col gap-4">
						{fields.map((field, index) => (
							<div
								key={field.key}
								className="border border-gray-200 rounded-lg p-4 bg-white"
							>
								<div className="flex items-center justify-between mb-3">
									<span className="text-sm text-gray-500">
										{sprintf(
											/* translators: %d: 試看影片序號 */
											__('Trial video #%d', 'power-course'),
											index + 1
										)}
									</span>
									<div className="flex items-center gap-1">
										<Tooltip title={__('Move up', 'power-course')}>
											<Button
												size="small"
												type="text"
												icon={<ArrowUpOutlined />}
												disabled={index === 0}
												onClick={() => move(index, index - 1)}
											/>
										</Tooltip>
										<Tooltip title={__('Move down', 'power-course')}>
											<Button
												size="small"
												type="text"
												icon={<ArrowDownOutlined />}
												disabled={index === fields.length - 1}
												onClick={() => move(index, index + 1)}
											/>
										</Tooltip>
										<Tooltip
											title={__('Remove this trial video', 'power-course')}
										>
											<Button
												size="small"
												type="text"
												danger
												icon={<DeleteOutlined />}
												onClick={() => remove(field.name)}
											/>
										</Tooltip>
									</div>
								</div>
								<VideoInput name={[field.name]} hideSubtitle />
							</div>
						))}

						<div className="flex items-center gap-3">
							<Button
								type="dashed"
								icon={<PlusOutlined />}
								disabled={reachedMax}
								onClick={() =>
									add({
										type: 'none',
										id: '',
										meta: {},
									})
								}
							>
								{__('Add trial video', 'power-course')}
							</Button>
							{reachedMax && (
								<span className="text-xs text-gray-500">
									{sprintf(
										/* translators: %d: 試看影片數量上限 */
										__(
											'At most %d trial videos can be added',
											'power-course'
										),
										MAX_TRIAL_VIDEOS
									)}
								</span>
							)}
						</div>
					</div>
				)
			}}
		</Form.List>
	)
}

export default TrialVideosList
