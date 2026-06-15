import { Tag, SelectProps } from 'antd'
import React from 'react'

/**
 * 多選 Select tag 的最大顯示寬度（px）
 *
 * 課程／商品名稱可能很長，未限制寬度時 antd 的 selection tag 會把整個欄位撐爆，
 * 擠壓同一列其他 UI。此值約 12rem，超過即以 ellipsis 截斷。
 */
const TAG_MAX_WIDTH = 192

/**
 * 共用的多選 Select tagRender
 *
 * 用於 course / product 等「選項名稱可能很長」的多選 Select，為每個已選 tag
 * 加上最大寬度限制：超過上限的文字以 ellipsis（…）截斷，hover 顯示完整名稱。
 *
 * 注意：
 * - 僅在 `mode="multiple"` / `mode="tags"` 下被 antd 呼叫；單選模式不受影響。
 * - 保留 closable / onClose，確保多選 tag 仍可移除。
 * - `onMouseDown` 阻擋預設行為與冒泡，避免點擊關閉鈕時誤觸下拉（antd 慣例）。
 */
export const ellipsisTagRender: NonNullable<SelectProps['tagRender']> = ({
	label,
	closable,
	onClose,
}) => {
	const onMouseDown = (event: React.MouseEvent<HTMLSpanElement>) => {
		event.preventDefault()
		event.stopPropagation()
	}

	// label 多數情況是字串（course/product name），用於 hover 完整顯示
	const title = typeof label === 'string' ? label : undefined

	return (
		<Tag
			closable={closable}
			onClose={onClose}
			onMouseDown={onMouseDown}
			style={{
				maxWidth: TAG_MAX_WIDTH,
				marginInlineEnd: 4,
				display: 'inline-flex',
				alignItems: 'center',
			}}
		>
			<span
				title={title}
				style={{
					overflow: 'hidden',
					textOverflow: 'ellipsis',
					whiteSpace: 'nowrap',
				}}
			>
				{label}
			</span>
		</Tag>
	)
}
