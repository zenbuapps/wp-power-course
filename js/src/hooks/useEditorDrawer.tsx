import { DrawerProps } from 'antd'
import { useState } from 'react'

/**
 * Editor Drawer
 * 用於 Notion 編輯器，沒有用 Form 包住
 *
 */

type TUseEditorDrawerParams = {
	drawerProps?: DrawerProps
	itemLabel?: string
}

export function useEditorDrawer(props?: TUseEditorDrawerParams) {
	const drawerProps = props?.drawerProps || {}
	const itemLabel = props?.itemLabel || '課程'
	const [open, setOpen] = useState(false)

	const show = () => {
		setOpen(true)
	}

	const close = () => {
		setOpen(false)
	}

	const mergedDrawerProps: DrawerProps = {
		title: itemLabel === '課程' ? '編輯課程重點描述' : `編輯${itemLabel}內容`,
		forceRender: false,
		placement: 'left',
		onClose: close,
		open,
		width: '50%',
		...drawerProps,
	}

	return {
		open,
		setOpen,
		show,
		close,
		drawerProps: mergedDrawerProps,
	}
}
