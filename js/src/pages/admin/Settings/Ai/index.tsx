import { __ } from '@wordpress/i18n'
import { Alert, Button, Card, Space, Spin } from 'antd'
import { memo, useCallback, useEffect, useState } from 'react'

import { TMcpSettings } from '@/types/mcp'

import { useMcpSettings, useSaveMcpSettings } from '../Mcp/hooks/useMcpSettings'

import { PermissionControl } from './PermissionControl'

/**
 * AI 設定 Tab（Issue #217）
 *
 * 集中管理 AI 相關功能的安全設定，目前提供：
 *  - MCP 權限控制（允許修改 / 允許刪除）
 *
 * 採用與 MCP Tab 相同的「獨立 form / 自帶 Save 按鈕」模式，不依賴外層 Form。
 * 兩個 Tab 共用同一個 pc_mcp_settings option，但後端 POST 為 PATCH 語意，
 * 各 Tab 各自 Save 不會互相覆蓋對方的欄位。
 */
const Ai = () => {
	const { settings, isLoading, isFetching, refetch } = useMcpSettings()
	const { save, isLoading: isSaving } = useSaveMcpSettings()

	const [allowUpdate, setAllowUpdate] = useState<boolean>(false)
	const [allowDelete, setAllowDelete] = useState<boolean>(false)
	const [isDirty, setIsDirty] = useState<boolean>(false)

	useEffect(() => {
		if (isFetching) {
			return
		}
		setAllowUpdate(settings.allow_update ?? false)
		setAllowDelete(settings.allow_delete ?? false)
		setIsDirty(false)
	}, [isFetching, settings.allow_update, settings.allow_delete])

	const handleAllowUpdateChange = useCallback((next: boolean) => {
		setAllowUpdate(next)
		setIsDirty(true)
	}, [])

	const handleAllowDeleteChange = useCallback((next: boolean) => {
		setAllowDelete(next)
		setIsDirty(true)
	}, [])

	const handleSave = useCallback(() => {
		// 一併送出 enabled / enabled_categories / rate_limit 已知值，
		// 防止未來後端改為 PUT 全替換語意時破裂
		const values: TMcpSettings = {
			enabled: settings.enabled,
			enabled_categories: settings.enabled_categories,
			...(typeof settings.rate_limit === 'number'
				? { rate_limit: settings.rate_limit }
				: {}),
			allow_update: allowUpdate,
			allow_delete: allowDelete,
		}
		save(values, () => {
			setIsDirty(false)
			refetch()
		})
	}, [
		allowUpdate,
		allowDelete,
		settings.enabled,
		settings.enabled_categories,
		settings.rate_limit,
		save,
		refetch,
	])

	if (isLoading) {
		return (
			<div className="flex justify-center py-16">
				<Spin />
			</div>
		)
	}

	return (
		<div className="flex flex-col gap-6 max-w-[960px]">
			<Alert
				type="info"
				showIcon
				message={__('AI permission settings', 'power-course')}
				description={__(
					'Control what AI can do via MCP tools. Read access is always allowed; modify and delete must be enabled explicitly.',
					'power-course'
				)}
			/>

			<Card>
				<PermissionControl
					allowUpdate={allowUpdate}
					allowDelete={allowDelete}
					onAllowUpdateChange={handleAllowUpdateChange}
					onAllowDeleteChange={handleAllowDeleteChange}
				/>

				<div className="mt-6 pt-4 border-t">
					<Space>
						<Button
							type="primary"
							onClick={handleSave}
							loading={isSaving}
							disabled={!isDirty}
						>
							{__('Save', 'power-course')}
						</Button>
						{isDirty && (
							<span className="text-xs text-orange-500">
								{__('Unsaved changes', 'power-course')}
							</span>
						)}
					</Space>
				</div>
			</Card>
		</div>
	)
}

export default memo(Ai)
