import { DeleteOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Form, FormItemProps, Button } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import { MediaLibraryModal, useMediaLibraryModal } from 'antd-toolkit/refine'
import { FC } from 'react'

import { useEnv } from '@/hooks'

import { getFullPath } from '../utils'

import SubtitleManager from './SubtitleManager'
import { TVideo, TVideoSlot } from './types'

/** 有效的 Video Slot 值，用於 runtime 驗證 */
const VALID_VIDEO_SLOTS: TVideoSlot[] = [
	'chapter_video',
	'feature_video',
	'trial_video',
]

type TBunnyProps = FormItemProps & {
	/**
	 * 影片所屬的 video slot；由父元件明確指定（特別是 Form.List 多影片試看場景，
	 * 此時 name 末元素是陣列數字 index，無法推斷出 slot 字串）。
	 *
	 * 未傳時 fallback 至「從 name 末元素推斷」邏輯，保持向下相容
	 * （chapter_video / feature_video / 單部 trial_video 場景）。
	 */
	videoSlot?: TVideoSlot
	/**
	 * 父層 Form.List 的路徑前綴；非 Form.List 場景不傳。
	 * 詳見 VideoInput/index.tsx 的 prop 說明。
	 */
	listName?: NamePath
}

const { Item } = Form
const Bunny: FC<TBunnyProps> = (formItemProps) => {
	const {
		videoSlot: videoSlotProp,
		listName,
		...restFormItemProps
	} = formItemProps
	const { BUNNY_LIBRARY_ID } = useEnv()
	const form = Form.useFormInstance()
	const name = formItemProps?.name
	if (!name) {
		throw new Error('name is required')
	}

	/**
	 * Video slot 解析優先順序：
	 *  1. 父元件明確傳入的 `videoSlot` prop（多部試看場景必須走此路徑）
	 *  2. fallback：從 name 末元素推斷（向下相容單部影片場景）
	 */
	const nameArray = Array.isArray(name) ? name : [name]
	const rawSlot = nameArray[nameArray.length - 1]
	const inferredSlot: TVideoSlot = VALID_VIDEO_SLOTS.includes(
		rawSlot as TVideoSlot
	)
		? (rawSlot as TVideoSlot)
		: 'chapter_video'
	const videoSlot: TVideoSlot = videoSlotProp ?? inferredSlot

	/**
	 * Form.List 場景下，useWatch / setFieldValue 不繼承 list 前綴，
	 * 需手動拼接 fullPath；非 Form.List 場景 fullPath === name。
	 *
	 * recordId（postId）固定在 root，不需拼前綴 —— 它代表 Course/Chapter 的 ID。
	 */
	const fullPath = getFullPath(name, listName)
	const recordId = Form.useWatch(['id'], form)

	// 取得後端傳來的 saved video
	const savedVideo: TVideo | undefined = Form.useWatch(fullPath, form)

	const { show, close, modalProps, setModalProps, ...mediaLibraryProps } =
		useMediaLibraryModal({
			onConfirm: (selectedItems) => {
				form.setFieldValue(fullPath, {
					type: 'bunny-stream-api',
					id: selectedItems?.[0]?.guid || '',
					meta: {},
				})
			},
		})

	const isEmpty = savedVideo?.id === ''

	const videoUrl = `https://iframe.mediadelivery.net/embed/${BUNNY_LIBRARY_ID}/${savedVideo?.id}?autoplay=false&loop=false&muted=false&preload=true&responsive=true`

	const handleDelete = () => {
		form.setFieldValue(fullPath, {
			type: 'none',
			id: '',
			meta: {},
		})
	}

	return (
		<div className="relative">
			<Button
				size="small"
				type="link"
				className="ml-0 mb-2 pl-0"
				onClick={show}
			>
				{__('Open Bunny media library', 'power-course')}
			</Button>
			<MediaLibraryModal
				modalProps={modalProps}
				mediaLibraryProps={{
					...mediaLibraryProps,
					limit: 1,
				}}
			/>
			<Item hidden {...restFormItemProps} />
			{/* 如果章節已經有存影片，則顯示影片，有瀏覽器 preview，則以 瀏覽器 preview 優先 */}
			{recordId && !isEmpty && (
				<>
					<div className="relative aspect-video rounded-lg border border-dashed border-gray-300">
						<div className="absolute w-full h-full top-0 left-0 p-2">
							<div className="w-full h-full rounded-xl overflow-hidden">
								<div
									className={`rounded-xl bg-gray-200 ${!isEmpty ? 'tw-block' : 'tw-hidden'}`}
									style={{
										position: 'relative',
										paddingTop: '56.25%',
									}}
								>
									<iframe
										title={__('Video player', 'power-course')}
										className="border-0 absolute top-0 left-0 w-full h-full rounded-xl"
										src={videoUrl}
										loading="lazy"
										allow="encrypted-media;picture-in-picture;"
										allowFullScreen={true}
									></iframe>

									<div
										onClick={handleDelete}
										className="group absolute top-4 right-4 rounded-md size-12 bg-white shadow-lg flex justify-center items-center transition duration-300 hover:bg-red-500 cursor-pointer"
									>
										<DeleteOutlined className="text-red-500 group-hover:text-white" />
									</div>
								</div>
							</div>
						</div>
					</div>
					<SubtitleManager postId={recordId} videoSlot={videoSlot} />
				</>
			)}
		</div>
	)
}

export default Bunny
