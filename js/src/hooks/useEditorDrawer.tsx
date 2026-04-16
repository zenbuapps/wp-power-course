import { __, sprintf } from '@wordpress/i18n'
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
	const itemLabel = props?.itemLabel || __('Course', 'power-course')
	const [open, setOpen] = useState(false)

	const show = () => {
		setOpen(true)
	}

	const close = () => {
		setOpen(false)
	}

	const mergedDrawerProps: DrawerProps = {
		title:
			itemLabel === __('Course', 'power-course')
				? __('Edit course highlight description', 'power-course')
				: sprintf(
						// translators: %s: 項目名稱（如章節、單元等）
						__('Edit %s content', 'power-course'),
						itemLabel
					),
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
