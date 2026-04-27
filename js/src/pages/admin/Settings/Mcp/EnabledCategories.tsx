import { Badge, Button, Checkbox, Space } from 'antd'
import { memo, useCallback, useMemo } from 'react'

import { MCP_CATEGORIES, TMcpCategory } from '@/types/mcp'

type TEnabledCategoriesProps = {
	/** 目前已啟用的 categories */
	value: TMcpCategory[]
	/** 變更時回呼 */
	onChange: (next: TMcpCategory[]) => void
	/** 是否 disabled（MCP 總開關關閉時） */
	disabled?: boolean
}

/**
 * MCP 允許的 Tool Categories 勾選面板
 *
 * 顯示 9 個 category 的 Checkbox，支援全選／全不選。
 * 值變更後由父層統一儲存，避免每次勾選都打 API。
 */
const EnabledCategoriesComponent = ({
	value,
	onChange,
	disabled = false,
}: TEnabledCategoriesProps) => {
	const allKeys = useMemo<TMcpCategory[]>(
		() => MCP_CATEGORIES.map((c) => c.key),
		[]
	)

	const isAllSelected = value.length === allKeys.length
	const isNoneSelected = value.length === 0

	const handleGroupChange = useCallback(
		(checkedValues: Array<string | number | boolean>) => {
			const next = checkedValues.filter(
				(v): v is TMcpCategory =>
					typeof v === 'string' && allKeys.includes(v as TMcpCategory)
			)
			onChange(next)
		},
		[onChange, allKeys]
	)

	const handleSelectAll = useCallback(() => {
		onChange(allKeys)
	}, [allKeys, onChange])

	const handleClearAll = useCallback(() => {
		onChange([])
	}, [onChange])

	return (
		<div>
			<div className="mb-3">
				<Space>
					<Button
						size="small"
						onClick={handleSelectAll}
						disabled={disabled || isAllSelected}
					>
						全選
					</Button>
					<Button
						size="small"
						onClick={handleClearAll}
						disabled={disabled || isNoneSelected}
					>
						全不選
					</Button>
				</Space>
			</div>
			<Checkbox.Group
				value={value}
				onChange={handleGroupChange}
				disabled={disabled}
				className="w-full"
			>
				<div className="grid grid-cols-1 md:grid-cols-3 gap-3">
					{MCP_CATEGORIES.map((category) => (
						<div
							key={category.key}
							className="border rounded p-3 hover:border-blue-400 transition-colors"
						>
							<Checkbox value={category.key} className="w-full">
								<div className="inline-flex items-center gap-2">
									<span className="font-medium">{category.label}</span>
									<span className="text-xs text-gray-400">
										({category.key})
									</span>
									<Badge
										count={category.toolCount}
										showZero
										style={{ backgroundColor: '#1677ff' }}
									/>
								</div>
							</Checkbox>
						</div>
					))}
				</div>
			</Checkbox.Group>
		</div>
	)
}

export const EnabledCategories = memo(EnabledCategoriesComponent)
