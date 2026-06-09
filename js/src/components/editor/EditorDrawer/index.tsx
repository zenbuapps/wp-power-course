import { DescriptionDrawer } from 'antd-toolkit'
import { memo, useId, type ComponentProps } from 'react'

import { useEnv } from '@/hooks'

type TEditorDrawerProps = ComponentProps<typeof DescriptionDrawer>

/**
 * EditorDrawer 編輯器抽屜包裝元件
 *
 * 包裝 antd-toolkit 的 DescriptionDrawer，當 Elementor 外掛未啟用時，
 * 隱藏「Elementor 編輯器」選項（Issue #241）。
 *
 * 背景：DescriptionDrawer 為外部套件（antd-toolkit），讀取 env 的 ELEMENTOR_ENABLED 後
 * 僅將 Elementor 選項設為 disabled（仍顯示、灰階），未提供隱藏單一選項的 prop。
 * 為符合「未啟用時不顯示」的需求，於此包裝層以「scoped CSS」把該 disabled 選項隱藏。
 *
 * 實作說明：
 * - 透過 useEnv() 取得 ELEMENTOR_ENABLED（由 Bootstrap.php 以 class_exists('Elementor\\Plugin')
 *   即時注入，runtime 判斷不快取，啟用／停用後重新載入頁面即反映最新狀態）。
 * - 該編輯器 Radio.Group 中只有 Elementor 選項可能為 disabled（Power 編輯器永不 disabled），
 *   故以 useId() 產生唯一 class 包住 DescriptionDrawer，僅在此 scope 內隱藏 disabled 選項，
 *   不會誤傷其他元件，也避免直接 fork 外部套件。
 */
const EditorDrawerComponent = ({
	initialEditor,
	...restProps
}: TEditorDrawerProps) => {
	const { ELEMENTOR_ENABLED } = useEnv()
	const scopeId = useId().replace(/:/g, '')
	const scopeClassName = `pc-editor-drawer-${scopeId}`

	// Elementor 未啟用時，避免初始編輯器仍指向已被隱藏的 elementor，
	// 一律退回 power-editor，否則「開始編輯」按鈕會嘗試開啟無效的 Elementor。
	const resolvedInitialEditor =
		!ELEMENTOR_ENABLED && initialEditor === 'elementor'
			? 'power-editor'
			: initialEditor

	return (
		<div className={scopeClassName}>
			{!ELEMENTOR_ENABLED && (
				<style>{`.${scopeClassName} .ant-radio-button-wrapper-disabled{display:none;}`}</style>
			)}
			<DescriptionDrawer {...restProps} initialEditor={resolvedInitialEditor} />
		</div>
	)
}

export const EditorDrawer = memo(EditorDrawerComponent)
