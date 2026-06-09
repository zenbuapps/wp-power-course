import { Form } from 'antd'
import { DescriptionDrawer } from 'antd-toolkit'
import { memo, useEffect, useId, type ComponentProps } from 'react'

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
 *
 * 修正殘留警告（Issue #241）：
 * - DescriptionDrawer 內部用 useFormInstance() 從外層 Form context 取得 form 實例，
 *   並以 (initialEditor !== watch('editor')) 計算「切換編輯器警告」與「開始編輯」按鈕的 disabled。
 * - 當舊課程／章節的 form `editor` 欄位仍存為 'elementor'，站長卻已停用 Elementor 時，
 *   本元件只覆寫了 initialEditor prop（退回 power-editor），但沒同步修正 form 欄位值，
 *   導致 ('power-editor' !== 'elementor') = true：唯一可見的 Power radio 看似未選取、
 *   紅字警告無故常駐、「開始編輯」按鈕被 disable。
 * - 故在此以「與 DescriptionDrawer 相同的方式」（Form.useFormInstance()）取得同一個 form 實例，
 *   於 Elementor 未啟用且 form editor === 'elementor' 時，一次性把欄位修正回 power-editor。
 */
const EditorDrawerComponent = ({
	initialEditor,
	...restProps
}: TEditorDrawerProps) => {
	const { ELEMENTOR_ENABLED } = useEnv()
	const scopeId = useId().replace(/:/g, '')
	const scopeClassName = `pc-editor-drawer-${scopeId}`

	// 取得外層 Form context 的同一個 form 實例（與 DescriptionDrawer 內部一致），
	// 不新建第二個 Form 實例；兩個 call site 都把本元件包在 <Form> 內，故可取得。
	const form = Form.useFormInstance()
	// 響應式追蹤 form 的 editor 欄位（call site 會在 mount 後非同步 setFieldsValue，
	// 待舊值 'elementor' 落入 form 後本 effect 會重跑並修正）。
	const currentEditor = Form.useWatch(['editor'], form)

	// Elementor 未啟用時，避免初始編輯器仍指向已被隱藏的 elementor，
	// 一律退回 power-editor，否則「開始編輯」按鈕會嘗試開啟無效的 Elementor。
	const resolvedInitialEditor =
		!ELEMENTOR_ENABLED && initialEditor === 'elementor'
			? 'power-editor'
			: initialEditor

	// 一次性修正殘留的 elementor 欄位值：
	// 只在「未啟用」時動作，啟用時完全不介入（維持現狀）。
	// setFieldValue 後 currentEditor 變為 'power-editor'，條件不再成立，故不會無限迴圈。
	useEffect(() => {
		if (!ELEMENTOR_ENABLED && currentEditor === 'elementor') {
			form.setFieldValue(['editor'], 'power-editor')
		}
	}, [ELEMENTOR_ENABLED, currentEditor, form])

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
