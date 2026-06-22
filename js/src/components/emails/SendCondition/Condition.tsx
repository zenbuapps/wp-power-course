import { useSelect } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import {
	Form,
	Select,
	InputNumber,
	Space,
	TimePicker,
	Input,
	Switch,
	TreeSelect,
	TreeSelectProps,
} from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import dayjs, { Dayjs } from 'dayjs'
import React, { useEffect, useMemo } from 'react'

import {
	TCourseBaseRecord,
	TChapterRecord,
} from '@/pages/admin/Courses/List/types'
import { ellipsisTagRender } from '@/utils'

import { TriggerAt, TriggerCondition, SendingType, SendingUnit } from './enum'

const { Item } = Form

const Condition = ({ email_ids }: { email_ids: string[] }) => {
	const form = Form.useFormInstance()

	const watchTriggerAt = Form.useWatch([TriggerAt.FIELD_NAME], form)
	const watchTriggerCondition = Form.useWatch(
		['condition', TriggerCondition.FIELD_NAME],
		form
	)
	const watchCourseIds = Form.useWatch(['condition', 'course_ids'], form)
	const watchSendingType = Form.useWatch(
		['condition', 'sending', SendingType.FIELD_NAME],
		form
	)
	const watchSendingUnit = Form.useWatch(
		['condition', 'sending', SendingUnit.FIELD_NAME],
		form
	)

	const { selectProps: courseSelectProps } = useSelect<TCourseBaseRecord>({
		resource: 'courses',
		dataProviderName: 'power-course',
		optionLabel: 'name',
		optionValue: 'id',
		onSearch: (value) => [
			{
				field: 's',
				operator: 'eq',
				value,
			},
		],
	})

	// 章節清單仍透過 useSelect 抓取（含巢狀子章節），但改用 TreeSelect 渲染，
	// 故只取用 query 結果建立 treeData；關鍵字搜尋改由 TreeSelect 前端 title 過濾。
	const { query: chapterQuery } = useSelect<TChapterRecord>({
		resource: 'chapters',
		dataProviderName: 'power-course',
		optionLabel: 'name',
		optionValue: 'id',
		// 章節（chapter CPT）與課程的關聯欄位是 post meta `parent_course_id`，
		// 不是 post_parent，故以 meta_query ... compare: IN 比對所選課程；
		// email 支援多課程，watchCourseIds 為陣列。
		// 留空（未選課程）走 else 分支抓全部，維持「留空 = 選擇所有章節」語意。
		filters: watchCourseIds?.length
			? [
					{
						field: 'posts_per_page',
						operator: 'eq',
						value: 100,
					},
					{
						field: 'meta_query[0][key]',
						operator: 'eq',
						value: 'parent_course_id',
					},
					{
						field: 'meta_query[0][value]',
						operator: 'eq',
						value: watchCourseIds,
					},
					{
						field: 'meta_query[0][compare]',
						operator: 'eq',
						value: 'IN',
					},
				]
			: [
					{
						field: 'posts_per_page',
						operator: 'eq',
						value: 100,
					},
				],
		queryOptions: {
			enabled: [TriggerAt.CHAPTER_FINISH, TriggerAt.CHAPTER_ENTER].includes(
				watchTriggerAt
			),
		},
	})

	// 由 API 回傳的頂層章節（含巢狀 chapters）建立 TreeSelect 的 treeData。
	// 頂層章節與子章節皆為獨立可勾選節點（搭配 treeCheckStrictly 不連動）。
	// 以 query 結果的穩定 reference 當依賴，`|| []` 在 memo factory 內展開，避免每次 render 重算。
	const chapterRecords = chapterQuery.data?.data
	const chapterTreeData = useMemo(
		() => buildChapterTreeData(chapterRecords || []),
		[chapterRecords]
	)
	// 章節 id → name 對照表，供回填時以 form 內的 number[] 重建 labeledValue。
	const chapterLabelMap = useMemo(
		() => buildChapterLabelMap(chapterRecords || []),
		[chapterRecords]
	)

	useEffect(() => {
		if (watchTriggerAt === TriggerAt.COURSE_LAUNCH) {
			form.setFieldValue(
				['condition', TriggerCondition.FIELD_NAME],
				TriggerCondition.EACH
			)
		}
	}, [watchTriggerAt])

	// 當使用者主動變更「觸發時機」或「選擇課程」時，清空已選的「選擇章節」
	// （dependent-field：父條件變了，子選擇就重置，避免殘留不屬於目前課程的章節）。
	// Refine useForm 的 hydration 是透過 formProps.initialValues + form.resetFields()
	// 載入既有 email，兩者皆不會標記欄位為 touched；唯有使用者實際操作 course/trigger
	// select 才會 touched，故以 isFieldTouched 當守門，避免打開既有 email 時誤清章節。
	useEffect(() => {
		const userChanged =
			form.isFieldTouched(['condition', 'course_ids']) ||
			form.isFieldTouched([TriggerAt.FIELD_NAME])
		if (!userChanged) {
			return
		}
		form.setFieldValue(['condition', 'chapter_ids'], undefined)
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [watchCourseIds, watchTriggerAt])

	return (
		<>
			<Space.Compact block>
				<Item
					label={__('Trigger timing', 'power-course')}
					name={[TriggerAt.FIELD_NAME]}
					initialValue={TriggerAt.COURSE_GRANTED}
					className="w-32"
				>
					<Select
						options={[
							{
								label: __('When course is granted', 'power-course'),
								value: TriggerAt.COURSE_GRANTED,
							},
							{
								label: __('When course is finished', 'power-course'),
								value: TriggerAt.COURSE_FINISH,
							},
							{
								label: __('When course is launched', 'power-course'),
								value: TriggerAt.COURSE_LAUNCH,
							},
							{
								label: __('When chapter is finished', 'power-course'),
								value: TriggerAt.CHAPTER_FINISH,
							},
							{
								label: __('When chapter is entered', 'power-course'),
								value: TriggerAt.CHAPTER_ENTER,
							},
						]}
					/>
				</Item>

				<Item
					className="flex-1"
					label={__('Select course', 'power-course')}
					name={['condition', 'course_ids']}
					tooltip={__(
						'Multiple selection supported, keyword search available',
						'power-course'
					)}
					help={__('Leave empty = select all courses', 'power-course')}
				>
					<Select
						{...defaultSelectProps}
						{...courseSelectProps}
						tagRender={ellipsisTagRender}
						placeholder={__(
							'Multiple selection supported, keyword search available',
							'power-course'
						)}
					/>
				</Item>

				{[TriggerAt.CHAPTER_FINISH, TriggerAt.CHAPTER_ENTER].includes(
					watchTriggerAt
				) && (
					<Item
						label={__('Select chapter', 'power-course')}
						name={['condition', 'chapter_ids']}
						className="flex-1"
						tooltip={__(
							'Multiple selection supported, keyword search available',
							'power-course'
						)}
						help={__('Leave empty = select all chapters', 'power-course')}
						// treeCheckStrictly 模式下 TreeSelect 的 value 為 labeledValue（{value,label}[]）；
						// 入口把 form 內的 number[] 轉成 labeledValue 以正確回填已勾項。
						getValueProps={(value: Array<string | number> | undefined) => ({
							value: toLabeledChapterValue(value, chapterLabelMap),
						})}
						// 出口把 labeledValue 正規化回 number[]，維持與後端相容的 payload。
						normalize={(value: TLabeledChapterValue) =>
							fromLabeledChapterValue(value)
						}
					>
						<TreeSelect
							treeData={chapterTreeData}
							treeCheckable
							treeCheckStrictly
							showSearch
							treeNodeFilterProp="title"
							treeDefaultExpandAll
							allowClear
							tagRender={ellipsisTagRender}
							className="w-full"
							placeholder={__(
								'Multiple selection supported, keyword search available',
								'power-course'
							)}
						/>
					</Item>
				)}

				<Item
					className="w-[10rem]"
					label={__('Trigger condition', 'power-course')}
					name={['condition', TriggerCondition.FIELD_NAME]}
					initialValue="each"
				>
					<Select
						options={[
							{
								label: __('When any one is met', 'power-course'),
								value: TriggerCondition.EACH,
							},
							{
								label: __('When all are met', 'power-course'),
								value: TriggerCondition.ALL,
								disabled: [TriggerAt.COURSE_LAUNCH].includes(watchTriggerAt),
							},
							{
								label: __('When reaching specified quantity', 'power-course'),
								value: TriggerCondition.QUANTITY_GREATER_THAN,
								disabled: [TriggerAt.COURSE_LAUNCH].includes(watchTriggerAt),
							},
						]}
					/>
				</Item>

				{watchTriggerCondition === TriggerCondition.QUANTITY_GREATER_THAN && (
					<Item
						className="w-20"
						label={__('Quantity', 'power-course')}
						name={['condition', 'qty']}
						initialValue={1}
					>
						<InputNumber min={1} className="w-20" />
					</Item>
				)}
			</Space.Compact>

			<Space.Compact block>
				<Item
					name={['condition', 'sending', SendingType.FIELD_NAME]}
					className="w-40"
					label={__('After trigger condition is met', 'power-course')}
					initialValue={SendingType.NOW}
				>
					<Select
						options={[
							{
								label: __('Send immediately', 'power-course'),
								value: SendingType.NOW,
							},
							{
								label: __('Send later', 'power-course'),
								value: SendingType.LATER,
							},
						]}
					/>
				</Item>

				{watchSendingType === SendingType.LATER && (
					<>
						<Item
							name={['condition', 'sending', 'value']}
							label=" "
							className="w-20"
							initialValue={1}
						>
							<InputNumber min={1} className="w-full" />
						</Item>

						<Item
							name={['condition', 'sending', SendingUnit.FIELD_NAME]}
							label=" "
							className="w-16"
							initialValue={SendingUnit.DAY}
						>
							<Select
								options={[
									{
										label: __('Day', 'power-course'),
										value: SendingUnit.DAY,
									},
									{
										label: __('Hour', 'power-course'),
										value: SendingUnit.HOUR,
									},
									{
										label: __('Minute', 'power-course'),
										value: SendingUnit.MINUTE,
									},
								]}
							/>
						</Item>

						{watchSendingUnit === SendingUnit.DAY && (
							<>
								<Item label=" " className="w-20">
									<Input
										defaultValue={__('later at', 'power-course')}
										className="pointer-events-none"
									/>
								</Item>

								<Item
									name={['condition', 'sending', 'range']}
									label=" "
									className="w-60"
									normalize={(values: [Dayjs, Dayjs] | undefined) =>
										values ? values?.map((v) => v?.format('HH:mm')) : []
									}
									getValueProps={(values: [Dayjs, Dayjs] | undefined) => {
										if (!Array.isArray(values)) {
											return {
												value: undefined,
											}
										}

										// format('HH:mm') to Dayjs
										return {
											value: values?.map((v) => dayjs(v, 'HH:mm')),
										}
									}}
								>
									<TimePicker.RangePicker
										defaultValue={[
											dayjs('08:00', 'HH:mm'),
											dayjs('12:00', 'HH:mm'),
										]}
										format="HH:mm"
										placeholder={[
											__('Start time', 'power-course'),
											__('End time', 'power-course'),
										]}
									/>
								</Item>
							</>
						)}
					</>
				)}
			</Space.Compact>

			<Item
				label={__('Allow repeat send', 'power-course')}
				name={['allow_repeat_send']}
				tooltip={__(
					'When disabled, each student receives this email at most once per enrollment period; re-granting access after revocation starts a new round.',
					'power-course'
				)}
				initialValue={true}
				getValueProps={(value) => ({
					checked: value !== 'no' && value !== false,
				})}
				normalize={(checked) => (checked ? 'yes' : 'no')}
			>
				<Switch
					checkedChildren={__('Enable', 'power-course')}
					unCheckedChildren={__('Disable', 'power-course')}
				/>
			</Item>
		</>
	)
}

export default Condition

/** TreeSelect 章節節點：title 顯示名稱、value 為章節 ID（number） */
type TChapterTreeNode = NonNullable<TreeSelectProps['treeData']>[number]

/**
 * treeCheckStrictly 模式下 TreeSelect 的值型別（labeledValue 陣列）
 *
 * 每個元素為 `{ value, label }`；value 可能是 string 或 number。
 * 也容許 undefined（尚未選取）以利在 getValueProps / normalize 中防呆。
 */
type TLabeledChapterValue =
	| Array<{ value: string | number; label?: React.ReactNode }>
	| undefined

/**
 * 由 API 回傳的頂層章節（含巢狀 chapters）遞迴建立 TreeSelect 的 treeData
 *
 * 每個章節（頂層或子章節）都對應一個獨立、可勾選的節點：
 * `title` 為章節名稱、`value` 為章節 ID（轉為 number 以維持 payload 型別）。
 * 頂層節點本身不 disable、不設 selectable=false，確保可獨立勾選。
 *
 * @param chapters API 回傳的頂層章節陣列
 * @return TreeSelect treeData
 */
function buildChapterTreeData(chapters: TChapterRecord[]): TChapterTreeNode[] {
	return chapters.map((chapter) => {
		const sub_chapters = chapter?.chapters || []
		const node: TChapterTreeNode = {
			title: chapter.name,
			value: Number(chapter.id),
		}

		if (sub_chapters.length > 0) {
			node.children = buildChapterTreeData(sub_chapters)
		}

		return node
	})
}

/**
 * 遞迴攤平所有章節（頂層 + 子章節）建立 id → name 對照表
 *
 * 供回填時依 form 內的 number[] 重建 labeledValue 的 label。
 *
 * @param chapters API 回傳的頂層章節陣列
 * @return Map<章節 ID（number）, 章節名稱>
 */
function buildChapterLabelMap(chapters: TChapterRecord[]): Map<number, string> {
	const map = new Map<number, string>()

	const walk = (list: TChapterRecord[]) => {
		list.forEach((chapter) => {
			map.set(Number(chapter.id), chapter.name)
			if (chapter?.chapters?.length) {
				walk(chapter.chapters)
			}
		})
	}

	walk(chapters)
	return map
}

/**
 * 將 form 內的章節 ID 陣列轉為 TreeSelect（treeCheckStrictly）需要的 labeledValue
 *
 * 既有 email 儲存的 chapter_ids 可能是 string[]，統一轉為 number 後對照 label。
 * 找不到名稱時 fallback 顯示 ID 字串，避免空白 tag。
 *
 * @param value    form 內的章節 ID 陣列（可能為 string 或 number）
 * @param labelMap 章節 id → name 對照表
 * @return labeledValue 陣列
 */
function toLabeledChapterValue(
	value: Array<string | number> | undefined,
	labelMap: Map<number, string>
): TLabeledChapterValue {
	if (!Array.isArray(value)) {
		return []
	}

	return value.map((id) => {
		const numericId = Number(id)
		return {
			value: numericId,
			label: labelMap.get(numericId) ?? String(id),
		}
	})
}

/**
 * 將 TreeSelect（treeCheckStrictly）的 labeledValue 正規化為章節 ID 的 number[]
 *
 * 寫回 form 的值維持「章節 ID 的 number[]」，與既有後端 payload 相容。
 *
 * @param value TreeSelect onChange 回傳的 labeledValue 陣列
 * @return 章節 ID 的 number[]
 */
function fromLabeledChapterValue(value: TLabeledChapterValue): number[] {
	if (!Array.isArray(value)) {
		return []
	}

	return value.map((item) => Number(item?.value))
}
