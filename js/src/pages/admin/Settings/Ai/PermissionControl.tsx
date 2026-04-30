import { __ } from '@wordpress/i18n'
import { Switch } from 'antd'
import { memo } from 'react'

import { Heading } from '@/components/general'

/**
 * MCP 權限控制區塊（Issue #217）
 *
 * 兩個 Switch：
 *  - 「允許修改」對應 Settings.allow_update
 *  - 「允許刪除」對應 Settings.allow_delete
 *
 * 預設都關閉（唯讀模式），需站長手動開啟才允許 AI 修改/刪除課程資料。
 */
type TPermissionControlProps = {
	allowUpdate: boolean
	allowDelete: boolean
	onAllowUpdateChange: (next: boolean) => void
	onAllowDeleteChange: (next: boolean) => void
	disabled?: boolean
}

const PermissionControlInner = ({
	allowUpdate,
	allowDelete,
	onAllowUpdateChange,
	onAllowDeleteChange,
	disabled = false,
}: TPermissionControlProps) => {
	return (
		<div>
			<Heading className="mt-0">
				{__('MCP permission control', 'power-course')}
			</Heading>
			<p className="text-sm text-gray-500 mb-6">
				{__(
					'MCP is read-only by default. Enable the switches below to allow AI to modify or delete data via MCP tools.',
					'power-course'
				)}
			</p>

			<div className="flex flex-col gap-6">
				<div className="flex items-start gap-4">
					<Switch
						checked={allowUpdate}
						onChange={onAllowUpdateChange}
						disabled={disabled}
						checkedChildren={__('On', 'power-course')}
						unCheckedChildren={__('Off', 'power-course')}
					/>
					<div className="flex-1">
						<div className="font-medium">
							{__('Allow update', 'power-course')}
						</div>
						<p className="text-sm text-gray-500 mt-1 mb-0">
							{__(
								'When enabled, AI can create, update, sort, and duplicate courses, chapters, students, etc.',
								'power-course'
							)}
						</p>
					</div>
				</div>

				<div className="flex items-start gap-4">
					<Switch
						checked={allowDelete}
						onChange={onAllowDeleteChange}
						disabled={disabled}
						checkedChildren={__('On', 'power-course')}
						unCheckedChildren={__('Off', 'power-course')}
					/>
					<div className="flex-1">
						<div className="font-medium">
							{__('Allow delete', 'power-course')}
						</div>
						<p className="text-sm text-gray-500 mt-1 mb-0">
							{__(
								'When enabled, AI can delete courses, chapters, remove students, and reset learning progress.',
								'power-course'
							)}
						</p>
					</div>
				</div>
			</div>

			<div className="mt-6 pt-4 border-t">
				<a
					href="https://github.com/zenbuapps/wp-power-course/blob/master/mcp.zh-TW.md"
					target="_blank"
					rel="noopener noreferrer"
					className="text-sm"
				>
					{__('How to use MCP →', 'power-course')}
				</a>
			</div>
		</div>
	)
}

export const PermissionControl = memo(PermissionControlInner)
