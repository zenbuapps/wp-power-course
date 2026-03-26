import { PlusOutlined, EyeOutlined, CloseOutlined } from '@ant-design/icons'
import { Form, Input, Select, Image, FormProps, FormInstance } from 'antd'
import {
	CopyText,
	DescriptionDrawer,
	defaultSelectProps,
	BlockNoteDrawer,
	defaultImage,
} from 'antd-toolkit'
import {
	TImage,
	MediaLibraryModal,
	useMediaLibraryModal,
} from 'antd-toolkit/wp'
import { memo, useEffect, useState } from 'react'

import { FiSwitch, VideoInput } from '@/components/formItem'
import { Heading, ListSelect, useListSelect } from '@/components/general'
import useOptions from '@/components/product/ProductTable/hooks/useOptions'
import {
	keyLabelMapper,
	termToOptions,
} from '@/components/product/ProductTable/utils'
import { useEnv } from '@/hooks'
import { useParseData, useRecord } from '@/pages/admin/Courses/Edit/hooks'
import { TUserRecord } from '@/pages/admin/Courses/List/types'

const { Item } = Form

const CourseDescriptionComponent = ({
	formProps,
}: {
	formProps: FormProps
}) => {
	const form = formProps.form as FormInstance
	const { options } = useOptions({ endpoint: 'courses/options' })
	const { product_cats = [], product_tags = [] } = options
	const { SITE_URL, COURSE_PERMALINK_STRUCTURE } = useEnv()
	const productUrl = `${SITE_URL}/${COURSE_PERMALINK_STRUCTURE}/`
	const [initTeacherIds, setInitTeacherIds] = useState<string[]>([])
	const course = useRecord()
	const parseData = useParseData()

	// 課程封面圖：使用 WordPress Media Library 選圖器
	const watchImages: TImage[] = (Form.useWatch(['images'], form) || [])?.filter(
		(i: TImage) => !!i
	)

	const { show, modalProps, ...mediaLibraryProps } = useMediaLibraryModal({
		initItems: watchImages,
		onConfirm: (selectedItems) => {
			form.setFieldValue(['images'], selectedItems)
		},
	})

	/** 移除課程封面圖 */
	const handleRemoveImage = (_imageId: string) => () => {
		form.setFieldValue(
			['images'],
			watchImages.filter(({ id: imageId }) => imageId !== _imageId)
		)
	}

	const { listSelectProps } = useListSelect<TUserRecord>({
		resource: 'users',
		searchField: 'search',
		filters: [
			{
				field: 'is_teacher',
				operator: 'eq',
				value: 'yes',
			},
		],
		initKeys: initTeacherIds,
	})

	const { selectedItems: selectedTeachers } = listSelectProps

	useEffect(() => {
		form.setFieldValue(
			['teacher_ids'],
			selectedTeachers.map((item) => item.id)
		)
	}, [selectedTeachers.length])

	useEffect(() => {
		if (course?.id && course?.teacher_ids?.length) {
			setInitTeacherIds(course?.teacher_ids || [])
		} else {
			setInitTeacherIds([])
		}
	}, [course])

	return (
		<Form {...formProps}>
			<div className="mb-12">
				<Heading>課程發佈</Heading>

				<Item name={['slug']} label="銷售網址">
					<Input
						addonBefore={productUrl}
						addonAfter={<CopyText text={`${productUrl}${course?.slug}`} />}
					/>
				</Item>

				<FiSwitch
					formItemProps={{
						name: ['status'],
						label: '發佈',
						getValueProps: (value) => ({ value: value === 'publish' }),
						normalize: (value) => (value ? 'publish' : 'draft'),
						hidden: true,
					}}
					switchProps={{
						checkedChildren: '發佈',
						unCheckedChildren: '草稿',
					}}
				/>
			</div>
			<div className="mb-12">
				<Heading>課程描述</Heading>

				<Item name={['id']} hidden normalize={() => undefined} />

				<div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
					<Item name={['name']} label="課程名稱">
						<Input allowClear />
					</Item>
					<Item
						name={['category_ids']}
						label={keyLabelMapper('product_category_id')}
						initialValue={[]}
					>
						<Select
							{...defaultSelectProps}
							options={termToOptions(product_cats)}
							placeholder="可多選"
						/>
					</Item>
					<Item
						name={['tag_ids']}
						label={keyLabelMapper('product_tag_id')}
						initialValue={[]}
					>
						<Select
							{...defaultSelectProps}
							options={termToOptions(product_tags)}
							placeholder="可多選"
						/>
					</Item>
				</div>
				<div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
					<div>
						{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
						<label className="text-sm pb-2 inline-block">簡短說明</label>
						<div>
							<BlockNoteDrawer
								resource="courses"
								dataProviderName="power-course"
								parseData={parseData}
							/>
						</div>
					</div>
					<div className="col-span-2">
						<DescriptionDrawer
							resource="courses"
							dataProviderName="power-course"
							initialEditor={course?.editor as 'power-editor' | 'elementor'}
							parseData={parseData}
						/>
					</div>

					<div className="mb-8">
						{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
						<label className="text-sm font-normal inline-block pb-2">
							課程封面圖
						</label>
						<Item name={['images']} hidden initialValue={[]} />
						<div className="flex flex-wrap gap-2">
							{watchImages?.map(({ id: _imageId, url }) => (
								<Image
									key={_imageId}
									className="product-image aspect-square rounded-lg object-cover w-24 h-24"
									preview={{
										mask: (
											<div className="flex flex-col items-center justify-center">
												<div>
													<EyeOutlined />
													<CloseOutlined
														className="ml-2"
														onClick={handleRemoveImage(_imageId)}
													/>
												</div>
											</div>
										),
										maskClassName: 'rounded-lg',
									}}
									src={url || defaultImage}
									fallback={defaultImage}
								/>
							))}
							{watchImages?.length < 1 && (
								<div
									className="group aspect-square rounded-lg cursor-pointer bg-gray-100 hover:bg-blue-100 border-dashed border-2 border-gray-200 hover:border-blue-200 transition-all duration-300 flex justify-center items-center w-24 h-24"
									onClick={show}
								>
									<PlusOutlined className="text-gray-500 group-hover:text-blue-500 transition-all duration-300" />
								</div>
							)}
						</div>
						<MediaLibraryModal
							modalProps={modalProps}
							mediaLibraryProps={{
								...mediaLibraryProps,
								limit: 1,
								uploadProps: {
									accept: 'image/*',
								},
							}}
						/>
					</div>
					<div className="mb-8">
						<p className="mb-3">課程封面影片</p>
						<VideoInput name={['feature_video']} />
					</div>
					<div className="mb-8">
						<p className="mb-3">課程免費試看影片</p>
						<VideoInput name={['trial_video']} />
					</div>
				</div>
			</div>
			<div className="mb-12">
				<Heading>講師資訊</Heading>
				<ListSelect<TUserRecord>
					listSelectProps={listSelectProps}
					rowName="display_name"
					rowUrl="user_avatar_url"
				/>
				<Item name={['teacher_ids']} hidden initialValue={[]} />
			</div>
		</Form>
	)
}

export const CourseDescription = memo(CourseDescriptionComponent)
