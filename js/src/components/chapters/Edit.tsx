import { ExclamationCircleFilled } from '@ant-design/icons'
import { Edit, useForm } from '@refinedev/antd'
import { Form, Input, Switch, Space, Button, Typography } from 'antd'
import { toFormData, CopyText, DescriptionDrawer } from 'antd-toolkit'
import { memo, useEffect } from 'react'

import { VideoInput, VideoLength, FiSwitch } from '@/components/formItem'
import { TChapterRecord } from '@/pages/admin/Courses/List/types'

const { Item } = Form
const { Text } = Typography

const ChapterEditComponent = ({ record }: { record: TChapterRecord }) => {
	const { id, name, permalink, slug, editor: initEditor } = record

	// 初始化資料
	const { formProps, form, saveButtonProps, mutation, onFinish } = useForm({
		action: 'edit',
		resource: 'chapters',
		dataProviderName: 'power-course',
		id,
		redirect: false,
		queryOptions: {
			enabled: false,
		},

		invalidates: ['list', 'detail'],
		warnWhenUnsavedChanges: true,
	})

	const watchStatus = Form.useWatch(['status'], form)
	const watchSlug = Form.useWatch(['slug'], form)

	useEffect(() => {
		form.setFieldsValue(record)
	}, [record])

	// 將 [] 轉為 '[]'，例如，清除原本分類時，如果空的，前端會是 undefined，轉成 formData 時會遺失
	const handleOnFinish = (values: Partial<TChapterRecord>) => {
		onFinish(toFormData(values))
	}

	// 將 permalink 找出 slug 以外的剩餘字串
	const chapterUrl = permalink?.replace(`${slug}/`, '')

	return (
		<Edit
			resource="chapters"
			dataProviderName="power-course"
			recordItemId={id}
			breadcrumb={null}
			goBack={null}
			headerButtons={() => null}
			title={
				<div className="pl-4">
					《編輯》 {name} <span className="text-gray-400 text-xs">#{id}</span>
				</div>
			}
			saveButtonProps={{
				...saveButtonProps,
				children: '儲存章節',
				icon: null,
				loading: mutation?.isLoading,
			}}
			footerButtons={({ defaultButtons }) => (
				<>
					<div className="text-red-500 font-bold mr-8">
						<ExclamationCircleFilled />{' '}
						章節和課程是分開儲存的，編輯完成請記得儲存
					</div>

					<Switch
						className="mr-4"
						checkedChildren="發佈"
						unCheckedChildren="草稿"
						value={watchStatus === 'publish'}
						onChange={(checked) => {
							form.setFieldValue(['status'], checked ? 'publish' : 'draft')
						}}
					/>
					<Space.Compact>
						<Button
							color="default"
							variant="filled"
							href={permalink}
							target="_blank"
							className="!inline-flex"
						>
							預覽
						</Button>
						{defaultButtons}
					</Space.Compact>
				</>
			)}
			wrapperProps={{
				style: {
					boxShadow: '0px 0px 16px 0px #ddd',
					paddingTop: '1rem',
					borderRadius: '0.5rem',
				},
			}}
		>
			<Form {...formProps} onFinish={handleOnFinish} layout="vertical">
				<Item name={['name']} label="章節名稱">
					<Input allowClear />
				</Item>

				<Item name={['slug']} label="網址">
					<Input
						allowClear
						addonBefore={
							<Text className="max-w-[25rem] text-left" ellipsis>
								{chapterUrl}
							</Text>
						}
						addonAfter={<CopyText text={`${chapterUrl}${watchSlug}`} />}
					/>
				</Item>

				<div className="mb-8">
					<DescriptionDrawer
						resource="chapters"
						dataProviderName="power-course"
						initialEditor={initEditor as 'power-editor' | 'elementor'}
						parseData={toFormData}
					/>
				</div>
				<div className="mb-6 max-w-[20rem]">
					<p className="mb-3">上傳課程內容</p>
					<VideoInput name={['chapter_video']} />
				</div>
				<div className="mb-6 max-w-[20rem]">
					<p className="mb-3">課程時長</p>
					<VideoLength name={['chapter_length']} />
				</div>

				<div className="mb-6 max-w-[20rem]">
					<FiSwitch
						formItemProps={{
							name: ['enable_comment'],
							label: '顯示留言',
						}}
					/>
				</div>

				<Item name={['status']} hidden />
				<Item name={['depth']} hidden />
				<Item name={['id']} hidden />
			</Form>
		</Edit>
	)
}

export const ChapterEdit = memo(ChapterEditComponent)
