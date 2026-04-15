import { Alert, Button, Card, InputNumber, Space, Spin, Switch } from 'antd'
import { memo, useCallback, useEffect, useState } from 'react'

import { Heading } from '@/components/general'
import { TMcpCategory, TMcpSettings } from '@/types/mcp'

import { ActivityLog } from './ActivityLog'
import { EnabledCategories } from './EnabledCategories'
import { useMcpSettings, useSaveMcpSettings } from './hooks/useMcpSettings'
import { TokensList } from './Tokens'

/**
 * MCP（Model Context Protocol）設定頁
 *
 * 讓站長管理：
 *  1. 啟用/停用整個 MCP Server
 *  2. 勾選允許 AI 呼叫的 tool categories
 *  3. 建立／撤銷 API Tokens
 *  4. 檢視最近的 AI 操作活動紀錄
 *
 * 注意：本 tab 使用獨立的 state / 儲存流程，不依賴 Settings 根層的 Form，
 *       因此父層 Settings 的「儲存」按鈕不會影響 MCP 設定。
 */
const Mcp = () => {
	const { settings, isLoading, isFetching, refetch } = useMcpSettings()
	const { save, isLoading: isSaving } = useSaveMcpSettings()

	const [enabled, setEnabled] = useState<boolean>(false)
	const [enabledCategories, setEnabledCategories] = useState<TMcpCategory[]>([])
	const [rateLimit, setRateLimit] = useState<number | undefined>(undefined)
	const [isDirty, setIsDirty] = useState<boolean>(false)

	// 初始化／重新同步伺服器端設定
	useEffect(() => {
		if (isFetching) {
			return
		}
		setEnabled(settings.enabled)
		setEnabledCategories(settings.enabled_categories)
		setRateLimit(settings.rate_limit)
		setIsDirty(false)
	}, [
		isFetching,
		settings.enabled,
		settings.enabled_categories,
		settings.rate_limit,
	])

	const handleToggleEnabled = useCallback((next: boolean) => {
		setEnabled(next)
		setIsDirty(true)
	}, [])

	const handleCategoriesChange = useCallback((next: TMcpCategory[]) => {
		setEnabledCategories(next)
		setIsDirty(true)
	}, [])

	const handleRateLimitChange = useCallback((next: number | null) => {
		setRateLimit(next ?? undefined)
		setIsDirty(true)
	}, [])

	const handleSave = useCallback(() => {
		const values: TMcpSettings = {
			enabled,
			enabled_categories: enabledCategories,
			...(typeof rateLimit === 'number' ? { rate_limit: rateLimit } : {}),
		}
		save(values, () => {
			setIsDirty(false)
			refetch()
		})
	}, [
		enabled,
		enabledCategories,
		rateLimit,
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
				message="MCP（Model Context Protocol）設定"
				description="啟用後，AI 工具可透過 API Token 呼叫 Power Course 的 MCP tools。請謹慎選擇允許的 tool categories 與核發 Token 的對象。"
			/>

			<Card
				title={
					<div className="flex items-center justify-between">
						<span>啟用 MCP Server</span>
						<Switch
							checked={enabled}
							onChange={handleToggleEnabled}
							checkedChildren="啟用"
							unCheckedChildren="停用"
						/>
					</div>
				}
			>
				<div
					className={
						enabled ? '' : 'opacity-50 pointer-events-none select-none'
					}
				>
					<Heading className="mt-0">允許的 Tool Categories</Heading>
					<p className="text-sm text-gray-500 mb-3">
						勾選後，AI 才能呼叫該分類底下的 MCP tools。未勾選的分類將被拒絕。
					</p>
					<EnabledCategories
						value={enabledCategories}
						onChange={handleCategoriesChange}
						disabled={!enabled}
					/>

					<Heading className="mt-6">Rate Limit（選填）</Heading>
					<p className="text-sm text-gray-500 mb-3">
						每分鐘每個 Token 可呼叫的最大次數，留空即使用後端預設值。
					</p>
					<InputNumber
						min={1}
						max={10000}
						value={rateLimit}
						onChange={handleRateLimitChange}
						placeholder="例如：60"
						disabled={!enabled}
						className="w-full max-w-[200px]"
					/>
				</div>

				<div className="mt-6 pt-4 border-t">
					<Space>
						<Button
							type="primary"
							onClick={handleSave}
							loading={isSaving}
							disabled={!isDirty}
						>
							儲存 MCP 設定
						</Button>
						{isDirty && (
							<span className="text-xs text-orange-500">有未儲存的變更</span>
						)}
					</Space>
				</div>
			</Card>

			<Card title="API Tokens">
				<p className="text-sm text-gray-500 mb-3">
					建立 Token 以供 AI 應用呼叫 MCP。每個 Token 可個別指定允許的
					capabilities，且只會在建立時顯示明文一次，請立即複製保存。
				</p>
				<TokensList disabled={!enabled} />
			</Card>

			<Card title="近期活動">
				<p className="text-sm text-gray-500 mb-3">
					檢視最近的 AI 操作紀錄，協助追蹤異常與稽核。
				</p>
				<ActivityLog />
			</Card>
		</div>
	)
}

export default memo(Mcp)
