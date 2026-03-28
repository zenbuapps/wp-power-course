import { Edit, useForm } from '@refinedev/antd'
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

	// CourseAnnouncement,
	CoursePrice,
	CourseBundles,
	CourseOther,
	CourseStudents,
	CourseAnalysis,
} from '@/pages/admin/Courses/Edit/tabs'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

const { Item } = Form

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

	const parseData = (values: Partial<TCourseRecord>) => {
		return formatDateRangeData(values, 'sale_date_range', [
			'date_on_sale_from',
			'date_on_sale_to',
		])
	}

	/**
	 * 表單提交前轉換資料
	 * 將 images 欄位轉為 image_id / gallery_image_ids，
	 * 並移除不需要的 files、images 欄位
	 */
	const handleOnFinish = (values: Partial<TCourseRecord>) => {
		const formattedValues = parseData(values)
		const {
			images = [],
			// @ts-ignore -- files 欄位已廢棄，從表單值中移除以免傳送
			files,
			...rest
		} = formattedValues
		const [mainImage, ...galleryImages] = images as TImage[]

		onFinish({
			...rest,
			image_id: mainImage ? mainImage.id : '0',
			gallery_image_ids: galleryImages?.length
				? galleryImages.map(({ id }) => id)
				: '[]',
		})
	}

	// 重組 formProps
	const formProps: FormProps = {
		..._formProps,
		layout: 'vertical',
		onFinish: handleOnFinish,
	}

	// TAB items
	const items: TabsProps['items'] = [
		{
			key: 'CourseDescription',
			forceRender: true,
			label: '課程描述',
			children: <CourseDescription formProps={formProps} />,
		},
		{
			key: 'CoursePrice',
			forceRender: true,
			label: '課程訂價',
			children: <CoursePrice formProps={formProps} />,
		},
		{
			key: 'CourseBundle',
			forceRender: false,
			label: '銷售方案',
			children: <CourseBundles />,
		},
		{
			key: 'Chapters',
			forceRender: false,
			label: '章節管理',
			children: <SortableChapters />,
		},
		{
			key: 'CourseQA',
			forceRender: true,
			label: 'QA設定',
			children: <CourseQA formProps={formProps} />,
		},

		// {
		// 	key: 'CourseAnnouncement',
		// 	forceRender: false,
		// 	label: '課程公告',
		// 	children: <CourseAnnouncement />,
		// },
		{
			key: 'CourseOther',
			forceRender: true,
			label: '其他設定',
			children: <CourseOther formProps={formProps} />,
		},
		{
			key: 'CourseStudents',
			forceRender: false,
			label: '學員管理',
			children: <CourseStudents />,
		},
		{
			key: 'CourseAnalysis',
			forceRender: false,
			label: '分析',
			children: <CourseAnalysis />,
		},
	]

	const disableSaveButton = [
		'CourseBundle',
		'Chapters',
		'CourseStudents',
		'CourseAnalysis',
	].includes(activeKey)

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
							children: '儲存',
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
												checkedChildren="發佈"
												unCheckedChildren="草稿"
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
										前往傳統商品編輯介面
									</Button>
									<Tooltip
										title={
											record?.classroom_link
												? undefined
												: '此課程還沒有章節，無法前往教室'
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
											前往教室
										</Button>
									</Tooltip>

									<Button
										href={`${SITE_URL}/${COURSE_PERMALINK_STRUCTURE}/${record?.slug}`}
										target="_blank"
										rel="noreferrer"
										className="ml-4"
										type="default"
									>
										前往銷售頁
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
