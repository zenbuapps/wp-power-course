/**
 * CSV 匯出工具列（Issue #131 P7）
 *
 * - 匯出批改用 CSV：以目前課程分頁 + 狀態篩選的範圍直接下載（可改後再匯入）
 * - 匯出單一作業 CSV：先選擇作業再下載
 *
 * 兩者都是 `Content-Disposition: attachment` 的下載端點，無法走 Refine 的 JSON
 * data provider，沿用 `components/user/UserTable` 的 `window.open` + `_wpnonce` 做法。
 */
import { __ } from '@wordpress/i18n'
import { Button, Modal, Select, Space } from 'antd'
import { memo, useState } from 'react'

import { useAssignmentDownloadUrl } from '../hooks'
import { type TExportButtonsProps } from '../types'

/**
 * 匯出按鈕元件
 */
const ExportButtonsComponent = ({
	courseId,
	status,
	assignmentOptions,
}: TExportButtonsProps) => {
	const { getGradingCsvUrl, getAssignmentCsvUrl } = useAssignmentDownloadUrl()
	const [isModalOpen, setIsModalOpen] = useState(false)
	const [assignmentId, setAssignmentId] = useState<number | undefined>(
		undefined
	)

	/** 匯出批改用（可往返）CSV */
	const handleGradingExport = () => {
		window.open(getGradingCsvUrl(courseId, status), '_blank')
	}

	/** 匯出選定作業的繳交 CSV */
	const handleAssignmentExport = () => {
		if (!assignmentId) {
			return
		}

		window.open(getAssignmentCsvUrl(assignmentId), '_blank')
		setIsModalOpen(false)
	}

	return (
		<>
			<Space>
				<Button onClick={handleGradingExport}>
					{__('Export grading CSV', 'power-course')}
				</Button>
				<Button onClick={() => setIsModalOpen(true)}>
					{__('Export single assignment CSV', 'power-course')}
				</Button>
			</Space>

			<Modal
				title={__('Select the assignment to export', 'power-course')}
				open={isModalOpen}
				okText={__('Confirm export', 'power-course')}
				cancelText={__('Cancel', 'power-course')}
				okButtonProps={{ disabled: !assignmentId }}
				onOk={handleAssignmentExport}
				onCancel={() => setIsModalOpen(false)}
			>
				<Select<number>
					className="w-full"
					value={assignmentId}
					onChange={setAssignmentId}
					options={assignmentOptions}
					placeholder={__('Please select an assignment', 'power-course')}
				/>
			</Modal>
		</>
	)
}

export const ExportButtons = memo(ExportButtonsComponent)
