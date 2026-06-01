import { InfoCircleOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Segmented, Tooltip, message } from 'antd'
import { memo, useState } from 'react'

import ConfirmModal from './ConfirmModal'
import useTypeChange from './hooks'

type TTypeSwitcherProps = {
	courseId: string | number
	recordType: 'simple' | 'external'
	studentCount: number
	chapterCount: number
	bundleCount: number
	/** 切換成功後由父層觸發 record refetch（讓 isExternal 與所有 Tabs 重算） */
	onSuccess: () => void
}

/**
 * Issue #235：課程編輯頁標題列旁的類型切換器
 * - 跨 Tab 全域可見（小型 Segmented）
 * - 右側 InfoCircle hover 顯示 Tooltip 說明兩種類型差異（明確排除「外訓」誤解）
 * - 切換動作觸發 ConfirmModal，確認後立刻送 API，成功 / 失敗皆關閉 loading 並反映 UI
 */
const TypeSwitcher = ({
	courseId,
	recordType,
	studentCount,
	chapterCount,
	bundleCount,
	onSuccess,
}: TTypeSwitcherProps) => {
	const [pendingTarget, setPendingTarget] = useState<
		'simple' | 'external' | null
	>(null)
	const { switchType, isMutating } = useTypeChange(courseId)

	/** Segmented 受控 value：切換中也以 recordType 為準，避免 UI 出現中間態 */
	const segmentedValue: 'simple' | 'external' = recordType

	const handleSegmentedChange = (value: string | number) => {
		const target = value as 'simple' | 'external'
		if (target === recordType) {
			return
		}
		setPendingTarget(target)
	}

	const handleCancel = () => {
		if (isMutating) return
		setPendingTarget(null)
	}

	const handleConfirm = () => {
		if (!pendingTarget) return
		switchType(pendingTarget, {
			onSuccess: () => {
				message.success(
					pendingTarget === 'external'
						? __(
								'Switched to external platform course successfully',
								'power-course'
							)
						: __('Switched to in-site course successfully', 'power-course')
				)
				setPendingTarget(null)
				onSuccess()
			},
			onError: () => {
				message.error(
					__(
						'Failed to switch course type. Please try again later.',
						'power-course'
					)
				)
				setPendingTarget(null)
			},
		})
	}

	const tooltipContent = (
		<div className="text-xs leading-relaxed">
			<div className="mb-1">
				{__(
					'In-site course: courses watched directly on this site, with chapter/video/student management.',
					'power-course'
				)}
			</div>
			<div>
				{__(
					'External platform course: redirects to courses on other platforms (e.g., Hahow, Udemy); only the sales page is shown on this site.',
					'power-course'
				)}
			</div>
		</div>
	)

	return (
		<>
			<div className="inline-flex items-center gap-2 ml-4 align-middle">
				<Segmented
					size="small"
					value={segmentedValue}
					disabled={isMutating}
					onChange={handleSegmentedChange}
					options={[
						{
							label: __('In-site course', 'power-course'),
							value: 'simple',
						},
						{
							label: __('External platform course', 'power-course'),
							value: 'external',
						},
					]}
				/>
				<Tooltip
					title={tooltipContent}
					placement="bottom"
					overlayStyle={{ maxWidth: 360 }}
				>
					<InfoCircleOutlined
						className="text-gray-400 cursor-help"
						aria-label={__('Course type', 'power-course')}
					/>
				</Tooltip>
			</div>
			<ConfirmModal
				open={!!pendingTarget}
				targetType={pendingTarget ?? 'external'}
				studentCount={studentCount}
				chapterCount={chapterCount}
				bundleCount={bundleCount}
				loading={isMutating}
				onConfirm={handleConfirm}
				onCancel={handleCancel}
			/>
		</>
	)
}

export default memo(TypeSwitcher)
