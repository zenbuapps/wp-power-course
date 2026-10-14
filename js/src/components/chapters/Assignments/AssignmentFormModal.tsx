import { type BaseKey } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, Input, InputNumber, Modal, Select } from 'antd'
import { BlockNote, getEditorHtml, useBlockNote } from 'antd-toolkit'
import { memo, useEffect } from 'react'

import { DatePicker, FiSwitch } from '@/components/formItem'

import { useAssignmentMutation, useChapterOptions } from './hooks'
import {
	TAssignment,
	TAssignmentFormValues,
	TAssignmentPayload,
	YES_NO,
	toYesNo,
} from './types'

const { Item } = Form

/** 附件數量上限的合法範圍（對齊後端 Crud::MAX_FILES_MIN / MAX_FILES_MAX） */
const MAX_FILES_RANGE = {
	MIN: 1,
	MAX: 20,
} as const

/**
 * 截止時間可接受的輸入格式
 * 第一個為顯示格式，其餘供使用者手動輸入時解析（只給日期時視為當天 00:00）
 */
const DUE_AT_FORMATS = [
	'YYYY-MM-DD HH:mm',
	'YYYY-MM-DD',
]

type TAssignmentFormModalProps = {
	/** 編輯中的作業；null 代表新增 */
	assignment: TAssignment | null
	/** 目前所在章節 ID（新增時的預設所屬章節） */
	chapterId: number
	/** 所屬課程 ID（沿用 useParsed().id 原值），用於取得「所屬章節」選項 */
	courseId: BaseKey | undefined
	/** 關閉 Modal */
	onClose: () => void
	/** 儲存成功後的回呼 */
	onSaved: () => void
}

/**
 * 新增 / 編輯作業表單 Modal
 *
 * 作業說明使用與課程 / 章節相同的 Power 編輯器（BlockNote），
 * 截止時間以站台時區的 DatePicker 收集，送出時轉成 10 位 timestamp（清空送 0）。
 */
const AssignmentFormModalComponent = ({
	assignment,
	chapterId,
	courseId,
	onClose,
	onSaved,
}: TAssignmentFormModalProps) => {
	const [form] = Form.useForm<TAssignmentFormValues>()
	const { saveAssignment, isMutating } = useAssignmentMutation()
	const { chapterOptions } = useChapterOptions(courseId)
	const { blockNoteViewProps } = useBlockNote()
	const { editor } = blockNoteViewProps

	// 編輯既有作業時，把原本的作業說明 HTML 灌回編輯器
	useEffect(() => {
		const instructions = assignment?.instructions ?? ''
		if (!editor || !instructions) {
			return
		}

		;(async () => {
			const blocks = await editor.tryParseHTMLToBlocks(instructions)
			editor.replaceBlocks(editor.document, blocks)
		})().catch(() => {
			// 解析失敗時保持編輯器空白，使用者仍可重新輸入；原內容在未送出前不受影響
		})
	}, [editor, assignment])

	/**
	 * 送出表單
	 * 驗證失敗（AntD 會自行標紅）或序列化失敗時不中斷流程，後者退回原本的作業說明
	 */
	const handleOk = async () => {
		let values: TAssignmentFormValues
		try {
			values = await form.validateFields()
		} catch {
			return
		}

		let instructions = assignment?.instructions ?? ''
		try {
			// antd-toolkit 的 getEditorHtml 型別宣告吃「預設 schema」的 BlockNoteEditor，
			// 而 useBlockNote() 回傳的是含自訂 block（alert / bunnyVideo / mediaLibrary）
			// 的 editor；runtime 是同一個實例，只有泛型不相容，故以 unknown 轉型對齊宣告。
			instructions = await getEditorHtml(
				editor as unknown as Parameters<typeof getEditorHtml>[0]
			)
		} catch {
			// 編輯器序列化失敗時沿用原內容，避免把作業說明覆蓋成空字串
		}

		const payload: TAssignmentPayload = {
			chapter_id: Number(values.chapter_id) || chapterId,
			title: values.title,
			instructions,
			allow_text: toYesNo(values.allow_text),
			allow_file: toYesNo(values.allow_file),
			required_text: toYesNo(values.required_text),
			required_file: toYesNo(values.required_file),
			max_files: Number(values.max_files) || MAX_FILES_RANGE.MIN,
			due_at: values.due_at ?? 0,
		}

		saveAssignment(assignment?.id ?? null, payload, onSaved)
	}

	return (
		<Modal
			open
			width={720}
			maskClosable={false}
			title={
				assignment
					? __('Edit assignment', 'power-course')
					: __('Add assignment', 'power-course')
			}
			okText={__('Save', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			confirmLoading={isMutating}
			onOk={handleOk}
			onCancel={onClose}
		>
			<Form form={form} layout="vertical" className="mt-4">
				<Item
					name="title"
					label={__('Assignment title', 'power-course')}
					initialValue={assignment?.title ?? ''}
					rules={[
						{
							required: true,
							message: __('Please enter the assignment title', 'power-course'),
						},
					]}
				>
					<Input allowClear />
				</Item>

				<Item label={__('Assignment instructions', 'power-course')}>
					<div className="border border-solid border-gray-200 rounded-md max-h-[20rem] overflow-y-auto py-2">
						<BlockNote {...blockNoteViewProps} />
					</div>
				</Item>

				<div className="grid grid-cols-2 sm:grid-cols-4 gap-x-4">
					<FiSwitch
						formItemProps={{
							name: 'allow_text',
							label: __('Allow text submission', 'power-course'),
							initialValue: assignment?.allow_text ?? YES_NO.YES,
						}}
					/>
					<FiSwitch
						formItemProps={{
							name: 'required_text',
							label: __('Text required', 'power-course'),
							initialValue: assignment?.required_text ?? YES_NO.NO,
						}}
					/>
					<FiSwitch
						formItemProps={{
							name: 'allow_file',
							label: __('Allow file submission', 'power-course'),
							initialValue: assignment?.allow_file ?? YES_NO.NO,
						}}
					/>
					<FiSwitch
						formItemProps={{
							name: 'required_file',
							label: __('File required', 'power-course'),
							initialValue: assignment?.required_file ?? YES_NO.NO,
						}}
					/>
				</div>

				<div className="grid grid-cols-1 sm:grid-cols-3 gap-x-4">
					<Item
						name="max_files"
						label={__('Max attachments', 'power-course')}
						initialValue={assignment?.max_files ?? MAX_FILES_RANGE.MIN}
					>
						<InputNumber
							className="w-full"
							min={MAX_FILES_RANGE.MIN}
							max={MAX_FILES_RANGE.MAX}
						/>
					</Item>

					<DatePicker
						formItemProps={{
							name: 'due_at',
							label: __('Due date', 'power-course'),
							initialValue: assignment?.due_at ?? 0,
						}}
						datePickerProps={{
							className: 'w-full',
							allowClear: true,
							format: DUE_AT_FORMATS,
							showTime: { format: 'HH:mm' },
						}}
					/>

					<Item
						name="chapter_id"
						label={__('Parent chapter', 'power-course')}
						initialValue={assignment?.chapter_id ?? chapterId}
					>
						<Select
							showSearch
							optionFilterProp="label"
							options={chapterOptions}
							placeholder={__('Select chapter', 'power-course')}
						/>
					</Item>
				</div>
			</Form>
		</Modal>
	)
}

export const AssignmentFormModal = memo(AssignmentFormModalComponent)
