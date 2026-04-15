import { useCreate, useParsed, HttpError } from '@refinedev/core'
import { Space, InputNumber, Button, Form } from 'antd'
import React, { memo } from 'react'

import { TChapterRecord } from '@/pages/admin/Courses/List/types'

const { Item } = Form

type TCreateManyResponse = number[]
type TCreateManyParams = {
	qty: number
	parent_course_id: number
	chapter_video: {
		type: 'none'
	}
}

const AddChapters = ({ records }: { records: TChapterRecord[] }) => {
	const { id } = useParsed()
	const [form] = Form.useForm()

	const { mutate, isLoading } = useCreate<
		TCreateManyResponse,
		HttpError,
		TCreateManyParams
	>({
		resource: 'chapters',
		dataProviderName: 'power-course',
	})

	const handleCreateMany = () => {
		const values = form.getFieldsValue() as TCreateManyParams
		mutate({
			values,
			invalidates: ['list'],
			successNotification: (data) => {
				const ids = data?.data || []
				const idsString = ids?.map((chapterId) => `#${chapterId}`).join(', ')
				return {
					message: `新增 ${values?.qty} 個章節成功 (${idsString})，可以在底部查看 ⬇️`,
					type: 'success',
				}
			},
		})
	}

	return (
		<Form form={form} className="w-full">
			<Space.Compact>
				<Button type="primary" loading={isLoading} onClick={handleCreateMany}>
					新增
				</Button>
				<Item name={['qty']}>
					<InputNumber className="w-40" addonAfter="個" />
				</Item>
			</Space.Compact>
			<Item name={['parent_course_id']} initialValue={id} hidden />
			<Item name={['chapter_video', 'type']} initialValue="none" hidden />
		</Form>
	)
}

export default memo(AddChapters)
