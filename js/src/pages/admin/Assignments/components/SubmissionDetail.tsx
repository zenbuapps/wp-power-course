/**
 * 繳交詳情（Issue #131 P7）
 *
 * 學員文字內容與附件、批改操作與最後批改時間、學生背景資料。
 * 附件不輸出實體路徑或可直連的 URL，一律經過權限端點下載
 * （`features/assignment/下載作業附件.feature`，DEC-08）。
 */
import { __, sprintf } from '@wordpress/i18n'
import { Divider, Drawer } from 'antd'
import { memo } from 'react'

import {
	useAssignmentDownloadUrl,
	useGradeSubmission,
	useStudentOverview,
} from '../hooks'
import {
	type TGradePayload,
	type TGradeResponse,
	type TSubmissionRecord,
} from '../types'
import { getAttachmentKindIcon, getAttachmentKindLabel } from '../utils'

import { GradeForm } from './GradeForm'
import { StudentOverview } from './StudentOverview'

type TSubmissionDetailProps = {
	/** 目前檢視的繳交紀錄，未選取時為 null */
	record: TSubmissionRecord | null
	/** 抽屜是否開啟 */
	open: boolean
	/** 關閉抽屜的回呼 */
	onClose: () => void
	/** 批改成功後的回呼，帶入批改後的繳交紀錄（更新詳情、重新取得列表與統計） */
	onGraded: (graded: TGradeResponse) => void
}

/**
 * 繳交詳情元件
 */
const SubmissionDetailComponent = ({
	record,
	open,
	onClose,
	onGraded,
}: TSubmissionDetailProps) => {
	const { getAttachmentUrl } = useAssignmentDownloadUrl()
	const { grade, isGrading } = useGradeSubmission()
	const { overview, refetch: refetchOverview } = useStudentOverview(
		record?.user_id ?? 0,
		record?.course_id ?? 0
	)

	/**
	 * 送出批改結果，成功後同步刷新背景資料與外層列表 / 統計
	 *
	 * @param payload 批改狀態與評語
	 */
	const handleSubmit = (payload: TGradePayload) => {
		if (!record) {
			return
		}

		grade(record.id, payload, (graded) => {
			refetchOverview()
			onGraded(graded)
		})
	}

	return (
		<Drawer
			title={__('Submission details', 'power-course')}
			open={open}
			onClose={onClose}
			width={720}
		>
			{!!record && (
				<div className="pc-assignment-detail">
					<div className="mb-2 font-semibold">
						{__('Submission content', 'power-course')}
					</div>
					<div className="whitespace-pre-wrap">{record.text_body}</div>

					<Divider />

					<div className="mb-2 font-semibold">
						{__('Attachments', 'power-course')}
					</div>
					{record.attachments.map((attachment) => (
						<div
							key={attachment.attachment_id}
							className="flex items-center gap-2"
						>
							<span title={getAttachmentKindLabel(attachment.kind)}>
								{getAttachmentKindIcon(attachment.kind)}
							</span>
							<a href={getAttachmentUrl(attachment.attachment_id)}>
								{attachment.file_name}
							</a>
						</div>
					))}

					<Divider />

					<div className="pc-assignment-graded-at mb-4">
						{sprintf(
							// translators: %s: 最後批改時間，未批改時為「尚未批改」
							__('Last graded at: %s', 'power-course'),
							record.graded_at ?? __('Not graded yet', 'power-course')
						)}
					</div>

					<GradeForm
						record={record}
						isGrading={isGrading}
						onSubmit={handleSubmit}
					/>

					<Divider />

					<StudentOverview overview={overview} />
				</div>
			)}
		</Drawer>
	)
}

export const SubmissionDetail = memo(SubmissionDetailComponent)
