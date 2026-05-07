import { DeleteOutlined, UploadOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Button, Select, Upload, Tag, Spin, Popconfirm } from 'antd'
import { FC, useState } from 'react'

import { useSubtitles } from './hooks'
import { TVideoSlot } from './types'

/** SubtitleManager 元件 Props */
type TSubtitleManagerProps = {
	/** Post ID，用於呼叫字幕相關 API */
	postId: number
	/** Video slot 名稱，例如 chapter_video, feature_video, trial_video */
	videoSlot?: TVideoSlot
}

/** 可選語言列表 */
const SUBTITLE_LANGUAGES = [
	{ value: 'zh-TW', label: '繁體中文' },
	{ value: 'zh-CN', label: '简体中文' },
	{ value: 'en', label: 'English' },
	{ value: 'ja', label: '日本語' },
	{ value: 'ko', label: '한국어' },
	{ value: 'vi', label: 'Tiếng Việt' },
	{ value: 'th', label: 'ไทย' },
	{ value: 'id', label: 'Bahasa Indonesia' },
	{ value: 'ms', label: 'Bahasa Melayu' },
	{ value: 'fr', label: 'Français' },
	{ value: 'de', label: 'Deutsch' },
	{ value: 'es', label: 'Español' },
	{ value: 'pt', label: 'Português' },
	{ value: 'ru', label: 'Русский' },
	{ value: 'ar', label: 'العربية' },
	{ value: 'hi', label: 'हिन्दी' },
] as const

/**
 * 字幕管理元件
 * 提供字幕上傳、列表顯示與刪除功能
 * 用於 Bunny Stream 影片的字幕管理
 */
const SubtitleManager: FC<TSubtitleManagerProps> = ({
	postId,
	videoSlot = 'chapter_video',
}) => {
	const [selectedLang, setSelectedLang] = useState<string | undefined>(
		undefined
	)

	const {
		subtitles,
		isLoading,
		isUploading,
		deletingLang,
		handleUpload,
		handleDelete,
	} = useSubtitles({ postId, videoSlot })

	/** 已上傳語言的 Set，用於過濾可選語言 */
	const uploadedLangs = new Set(subtitles.map((s) => s.srclang))

	/** 過濾已上傳的語言，只顯示未上傳的語言選項 */
	const availableLanguages = SUBTITLE_LANGUAGES.filter(
		(lang) => !uploadedLangs.has(lang.value)
	)

	/** 根據語言代碼取得語言 label */
	const getLangLabel = (srclang: string): string => {
		return (
			SUBTITLE_LANGUAGES.find((lang) => lang.value === srclang)?.label ||
			srclang
		)
	}

	return (
		<div className="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2">
			<h4 className="mb-1 text-sm font-semibold text-gray-700">
				{__('Subtitle manager', 'power-course')}
			</h4>

			{/* 上傳區塊 */}
			<div className="mb-1 flex items-center gap-2">
				<Select
					className="w-40"
					size="small"
					value={selectedLang}
					onChange={setSelectedLang}
					options={availableLanguages}
					placeholder={__('Please select subtitle language', 'power-course')}
					disabled={availableLanguages.length === 0}
				/>
				<Upload
					accept=".srt,.vtt"
					showUploadList={false}
					beforeUpload={(file) => {
						if (selectedLang) {
							handleUpload(file, selectedLang, {
								onSettled: () => setSelectedLang(undefined),
							})
						}
						return false
					}}
					disabled={
						isUploading || availableLanguages.length === 0 || !selectedLang
					}
				>
					<Button
						size="small"
						icon={<UploadOutlined />}
						loading={isUploading}
						disabled={availableLanguages.length === 0 || !selectedLang}
					>
						{__('Upload subtitle', 'power-course')}
					</Button>
				</Upload>
			</div>

			{/* 已上傳字幕列表 */}
			{isLoading && <Spin size="small" />}
			{!isLoading && subtitles.length > 0 && (
				<div className="flex flex-wrap gap-2">
					{subtitles.map((track) => (
						<Tag
							key={track.srclang}
							className="flex items-center gap-1 px-2 py-1"
						>
							<span className="text-xs">{getLangLabel(track.srclang)}</span>
							<Popconfirm
								title={__(
									'Are you sure you want to delete this subtitle?',
									'power-course'
								)}
								onConfirm={() => handleDelete(track.srclang)}
								okText={__('Confirm', 'power-course')}
								cancelText={__('Cancel', 'power-course')}
							>
								<Button
									type="text"
									size="small"
									danger
									icon={<DeleteOutlined />}
									loading={deletingLang === track.srclang}
									className="ml-1 h-auto p-0"
								/>
							</Popconfirm>
						</Tag>
					))}
				</div>
			)}
		</div>
	)
}

export default SubtitleManager
