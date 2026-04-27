import { EditOutlined } from '@ant-design/icons'
import { Edit, useForm } from '@refinedev/antd'
import { useParsed, useNavigation, useNotification } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Form, Button, Space } from 'antd'
import { NameId } from 'antd-toolkit'
import { notificationProps } from 'antd-toolkit/refine'
import React, { memo, useEffect, useMemo, useState } from 'react'

import { TTeacherDetails } from '@/components/teacher/types'
import { UserName } from '@/components/user'

import { Detail } from './Detail'
import { IsEditingContext, RecordContext } from './hooks'

/**
 * 講師 Edit 頁
 *
 * 結構對齊 Power Shop pages/admin/Users/Edit/index.tsx，但：
 * - 砍掉 billing/shipping flatten 邏輯（講師 Edit 不含 AutoFill Tab）
 * - 新增 is_teacher 守衛：資料載入完成後若 record.is_teacher !== 'yes'
 *   則 notification.warning + navigate('/teachers')，避免使用者直接打
 *   /teachers/edit/:non-teacher-id 進到非講師的編輯頁
 * - Context 注入：IsEditingContext + RecordContext，供 Detail 各 Tab 消費
 */
const EditComponent = () => {
	const { id } = useParsed()
	const [isEditing, setIsEditing] = useState(false)
	const { list } = useNavigation()
	const { open: openNotification } = useNotification()

	const { formProps, saveButtonProps, query, mutation, onFinish } =
		useForm<TTeacherDetails>({
			action: 'edit',
			resource: 'users',
			id,
			redirect: false,
			...notificationProps,
			queryMeta: {
				variables: {
					meta_keys: ['is_teacher'],
				},
			},
		})

	const record: TTeacherDetails | undefined = useMemo(
		() => query?.data?.data,
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[query?.isFetching]
	)

	// 切 edit 模式時 reset 欄位，避免殘留上次未儲存的值
	useEffect(() => {
		if (isEditing) {
			formProps?.form?.resetFields()
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [isEditing])

	/**
	 * is_teacher 守衛：資料載入完成後檢查是否為講師；
	 * 非講師則跳 warning 並 navigate 回列表頁。
	 */
	useEffect(() => {
		if (query?.isFetching || !record?.id) {
			return
		}
		const isTeacher =
			record?.is_teacher === true ||
			(record?.is_teacher as unknown as string) === 'yes'
		if (!isTeacher) {
			openNotification?.({
				type: 'error',
				message: __('User is not an instructor', 'power-course'),
			})
			list('teachers')
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [record?.id, query?.isFetching])

	return (
		<IsEditingContext.Provider value={isEditing}>
			<RecordContext.Provider value={record}>
				<div className="sticky-card-actions sticky-tabs-nav">
					<Edit
						resource="teachers"
						title={
							<NameId name={record?.display_name || ''} id={record?.id || ''} />
						}
						headerButtons={() => null}
						saveButtonProps={{
							...saveButtonProps,
							children: __('Save', 'power-course'),
							icon: null,
							loading: mutation?.isLoading,
						}}
						isLoading={query?.isLoading}
						footerButtons={({ defaultButtons }) => (
							<>
								{!isEditing && (
									<Button
										type="default"
										onClick={() => setIsEditing(true)}
										icon={<EditOutlined />}
									>
										{__('Edit user', 'power-course')}
									</Button>
								)}

								{isEditing && (
									<Space.Compact>
										<Button type="default" onClick={() => setIsEditing(false)}>
											{__('Cancel', 'power-course')}
										</Button>
										{defaultButtons}
									</Space.Compact>
								)}
							</>
						)}
					>
						<Form {...formProps} onFinish={onFinish} layout="vertical">
							<div className="flex justify-between pc-nav py-2">
								<div>{record && <UserName record={record} />}</div>
								{record?.edit_url && (
									<Button
										type="default"
										target="_blank"
										href={record?.edit_url}
									>
										{__('Go to classic user edit page', 'power-course')}
									</Button>
								)}
							</div>
							<Detail />
						</Form>
					</Edit>
				</div>
			</RecordContext.Provider>
		</IsEditingContext.Provider>
	)
}

const TeacherEdit = memo(EditComponent)

export default TeacherEdit
