/**
 * 作業批改頁的共用顯示對照（Issue #131 P7）
 *
 * 批改狀態與附件大類的中文標籤，必須與後端
 * `Assignment\Utils\Utils::status_label()` / `resolve_attachment_kind()`
 * 用同一組 msgid 與 msgctxt，同一筆資料在後台與 CSV 不得出現兩種說法。
 */
import {
	FileExcelOutlined,
	FileImageOutlined,
	FileOutlined,
	FilePdfOutlined,
	FileTextOutlined,
	FileZipOutlined,
	PlayCircleOutlined,
	SoundOutlined,
} from '@ant-design/icons'
import { _x } from '@wordpress/i18n'
import type { ReactNode } from 'react'

import {
	ATTACHMENT_KIND,
	SUBMISSION_STATUS,
	type TAttachmentKind,
	type TSubmissionStatus,
} from './types'

/**
 * 批改狀態的中文標籤
 *
 * @param status 批改狀態
 */
export const getStatusLabel = (status: TSubmissionStatus): string => {
	switch (status) {
		case SUBMISSION_STATUS.PASS:
			return _x('Passed', 'assignment submission status', 'power-course')
		case SUBMISSION_STATUS.FAIL:
			return _x('Failed', 'assignment submission status', 'power-course')
		default:
			return _x('Pending', 'assignment submission status', 'power-course')
	}
}

/**
 * 批改狀態對應的 Ant Design Tag 顏色
 *
 * @param status 批改狀態
 */
export const getStatusColor = (status: TSubmissionStatus): string => {
	switch (status) {
		case SUBMISSION_STATUS.PASS:
			return 'green'
		case SUBMISSION_STATUS.FAIL:
			return 'red'
		default:
			return 'orange'
	}
}

/**
 * 附件大類的中文標籤（顯示於圖示的 title 屬性）
 *
 * @param kind 附件大類
 */
export const getAttachmentKindLabel = (kind: TAttachmentKind): string => {
	switch (kind) {
		case ATTACHMENT_KIND.IMAGE:
			return _x('Image', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.VIDEO:
			return _x('Video', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.AUDIO:
			return _x('Audio', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.PDF:
			return _x('PDF', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.DOC:
			return _x('Document', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.SPREADSHEET:
			return _x('Spreadsheet', 'assignment attachment kind', 'power-course')
		case ATTACHMENT_KIND.ARCHIVE:
			return _x('Archive', 'assignment attachment kind', 'power-course')
		default:
			return _x('File', 'assignment attachment kind', 'power-course')
	}
}

/**
 * 附件大類對應的圖示
 *
 * @param kind 附件大類
 */
export const getAttachmentKindIcon = (kind: TAttachmentKind): ReactNode => {
	switch (kind) {
		case ATTACHMENT_KIND.IMAGE:
			return <FileImageOutlined />
		case ATTACHMENT_KIND.VIDEO:
			return <PlayCircleOutlined />
		case ATTACHMENT_KIND.AUDIO:
			return <SoundOutlined />
		case ATTACHMENT_KIND.PDF:
			return <FilePdfOutlined />
		case ATTACHMENT_KIND.DOC:
			return <FileTextOutlined />
		case ATTACHMENT_KIND.SPREADSHEET:
			return <FileExcelOutlined />
		case ATTACHMENT_KIND.ARCHIVE:
			return <FileZipOutlined />
		default:
			return <FileOutlined />
	}
}
