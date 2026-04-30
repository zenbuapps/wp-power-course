import { Edit, useForm } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import { Tabs, TabsProps, Form, Switch, Button, Tooltip, FormProps } from 'antd'
import { formatDateRangeData } from 'antd-toolkit'
import { TImage } from 'antd-toolkit/wp'
import { memo, useMemo, useState } from 'react'

import { SortableChapters } from '@/components/course'
import { useEnv } from '@/hooks'
import {
	RecordContext,
	ParseDataContext,
} from '@/pages/admin/Courses/Edit/hooks'
import {
	CourseDescription,
	CourseQA,
	CourseAnnouncement,
	CoursePrice,
	CourseBundles,
	CourseOther,
	CourseStudents,
	CourseAnalysis,
} from '@/pages/admin/Courses/Edit/tabs'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

const { Item } = Form

/** 外部課程隱藏的 Tab keys */
const EXTERNAL_HIDDEN_TABS = [
	'CourseBundle',
	'Chapters',
	'CourseStudents',
	'CourseAnalysis',
]

/**
 * Issue #203：Courses Edit 頁可清空的選填欄位清單。
 * - 未送 key = 保持原狀（向下相容）
 * - 送 '' = 清空
 *
 * 新增欄位時請同步更新此清單。後端對應實作：
 *   - data 欄位：`handle_save_course_data`
 *   - meta 欄位：`handle_save_course_meta_data`
 */
const CLEARABLE_FIELDS = [
	'sale_price',
	'date_on_sale_from',
	'date_on_sale_to',
	'short_description',
	'slug',
	'purchase_note',
	'limit_type',
	'limit_value',
	'limit_unit',
	'course_schedule',
	'feature_video',
	'trial_video',
	'button_text',
	'sku',
] as const

export const CoursesEdit = () => {
	const { SITE_URL, COURSE_PERMALINK_STRUCTURE } = useEnv()
	const [activeKey, setActiveKey] = useState('CourseDescription')

	// 初始化資料
	const {
		formProps: _formProps,
		saveButtonProps,
		query,
		mutation,
		onFinish,
	} = useForm<TCourseRecord>({
		dataProviderName: 'power-course',
		redirect: false,
	})

	const record = useMemo(() => {
		return query?.data?.data
	}, [query])

	// 判斷是否為外部課程
	// 編輯模式：從 record.type 判斷；新增模式：從 form is_external 欄位判斷
	const isExternalFromRecord = record?.type === 'external'
	const watchIsExternal = Form.useWatch(['is_external'], _formProps.form)
	const isExternal =
		isExternalFromRecord ||
		watchIsExternal === true ||
		watchIsExternal === 'true'

	const parseData = (values: Partial<TCourseRecord>) => {
		return formatDateRangeData(values, 'sale_date_range', [
			'date_on_sale_from',
			'date_on_sale_to',
		])
	}

	/**
	 * 表單提交前轉換資料
	 * 將 images 欄位轉為 image_id / gallery_image_ids，
	 * 並移除不需要的 files、images 欄位。
	 *
	 * Issue #203：對 CLEARABLE_FIELDS 清單內的欄位，若值為 null/undefined/NaN，
	 * normalize 為空字串（''），確保 axios JSON 序列化不會省略該 key，
	 * 後端收到空字串能正確觸發清空語義。
	 */
	const handleOnFinish = (values: Partial<TCourseRecord>) => {
		const formattedValues = parseData(values) as Record<string, unknown>

		// Issue #203: 對可清空欄位 normalize null/undefined/NaN → ''
		const normalized: Record<string, unknown> = { ...formattedValues }
		for (const key of CLEARABLE_FIELDS) {
			if (!(key in normalized)) continue
			const v = normalized[key]
			if (
				v === undefined ||
				v === null ||
				(typeof v === 'number' && Number.isNaN(v))
			) {
				normalized[key] = ''
			}
		}

		const {
			images = [],
			// @ts-ignore -- files 欄位已廢棄，從表單值中移除以免傳送
			files,
			...rest
		} = normalized
		const [mainImage, ...galleryImages] = images as TImage[]

		onFinish({
			...rest,
			image_id: mainImage ? mainImage.id : '0',
			gallery_image_ids: galleryImages?.length
				? galleryImages.map(({ id }: TImage) => id)
				: '[]',
		})
	}

	// 重組 formProps
	const formProps: FormProps = {
		..._formProps,
		layout: 'vertical',
		onFinish: handleOnFinish,
	}

	// 所有 TAB items
	const allItems: TabsProps['items'] = [
		{
			key: 'CourseDescription',
			forceRender: true,
			label: __('Course Description', 'power-course'),
			children: <CourseDescription formProps={formProps} />,
		},
		{
			key: 'CoursePrice',
			forceRender: true,
			label: __('Course Pricing', 'power-course'),
			children: <CoursePrice formProps={formProps} />,
		},
		{
			key: 'CourseBundle',
			forceRender: false,
			label: __('Bundles', 'power-course'),
			children: <CourseBundles />,
		},
		{
			key: 'Chapters',
			forceRender: false,
			label: __('Chapters', 'power-course'),
			children: <SortableChapters />,
		},
		{
			key: 'CourseQA',
			forceRender: true,
			label: __('Q&A Settings', 'power-course'),
			children: <CourseQA formProps={formProps} />,
		},

		{
			key: 'CourseAnnouncement',
			forceRender: false,
			label: __('Announcements', 'power-course'),
			children: <CourseAnnouncement />,
		},
		{
			key: 'CourseOther',
			forceRender: true,
			label: __('Other Settings', 'power-course'),
			children: <CourseOther formProps={formProps} />,
		},
		{
			key: 'CourseStudents',
			forceRender: false,
			label: __('Students', 'power-course'),
			children: <CourseStudents />,
		},
		{
			key: 'CourseAnalysis',
			forceRender: false,
			label: __('Analytics', 'power-course'),
			children: <CourseAnalysis />,
		},
	]

	// 外部課程隱藏不適用的 Tab
	const items = isExternal
		? allItems.filter(
				(item) => !EXTERNAL_HIDDEN_TABS.includes(item?.key as string)
			)
		: allItems

	const disableSaveButton =
		[
			'CourseBundle',
			'Chapters',
			'CourseStudents',
			'CourseAnalysis',
		].includes(activeKey) && !isExternal

	return (
		<div className="sticky-card-actions sticky-tabs-nav">
			<ParseDataContext.Provider value={parseData}>
				<RecordContext.Provider value={record}>
					<Edit
						resource="courses"
						dataProviderName="power-course"
						title={
							<>
								{record?.name}{' '}
								<span className="text-gray-400 text-xs">#{record?.id}</span>
							</>
						}
						headerButtons={() => null}
						saveButtonProps={{
							...saveButtonProps,
							children: __('Save', 'power-course'),
							icon: null,
							loading: mutation?.isLoading,
						}}
						footerButtons={({ defaultButtons }) =>
							disableSaveButton ? null : (
								<>
									<Form {...formProps}>
										<Item
											noStyle
											name={['status']}
											getValueProps={(value) => {
												return {
													value: value === 'publish',
												}
											}}
											normalize={(value) => {
												return value ? 'publish' : 'draft'
											}}
										>
											<Switch
												className="mr-4"
												checkedChildren={__('Published', 'power-course')}
												unCheckedChildren={__('Draft', 'power-course')}
												disabled={disableSaveButton}
											/>
										</Item>
									</Form>
									{defaultButtons}
								</>
							)
						}
						isLoading={query?.isLoading}
					>
						<Tabs
							activeKey={activeKey}
							onChange={(key) => setActiveKey(key)}
							items={items}
							tabBarExtraContent={
								<>
									<Button
										className="ml-4"
										type="default"
										href={record?.edit_url}
										target="_blank"
										rel="noreferrer"
									>
										{__('Open Classic Product Editor', 'power-course')}
									</Button>
									{!isExternal && (
										<Tooltip
											title={
												record?.classroom_link
													? undefined
													: __(
															'This course has no chapters yet, the classroom is unavailable.',
															'power-course'
														)
											}
										>
											<Button
												href={record?.classroom_link}
												target="_blank"
												rel="noreferrer"
												className="ml-4"
												type="default"
												disabled={!record?.classroom_link}
											>
												{__('Open Classroom', 'power-course')}
											</Button>
										</Tooltip>
									)}

									<Button
										href={`${SITE_URL}/${COURSE_PERMALINK_STRUCTURE}/${record?.slug}`}
										target="_blank"
										rel="noreferrer"
										className="ml-4"
										type="default"
									>
										{__('View Sales Page', 'power-course')}
									</Button>
								</>
							}
						/>
					</Edit>
				</RecordContext.Provider>
			</ParseDataContext.Provider>
		</div>
	)
}

export default memo(CoursesEdit)
