import { useCreate, useUpdate, useInvalidate } from '@refinedev/core'
import { DrawerProps, Button, FormInstance, Popconfirm, Form } from 'antd'
import { toFormData } from 'antd-toolkit'
import { isEqual } from 'lodash-es'
import { useState, useEffect, useRef } from 'react'

import { TUserRecord } from '@/pages/admin/Courses/List/types'

export function useUserFormDrawer({
	form,
	resource = 'users',
	drawerProps,
	users,
}: {
	form: FormInstance
	resource?: string
	drawerProps?: DrawerProps
	users?: TUserRecord[]
}) {
	const [open, setOpen] = useState(false)
	const [record, setRecord] = useState<TUserRecord | undefined>(undefined)
	const isUpdate = !!Form.useWatch(['id'], form) // 如果沒有傳入 record 就走新增課程，否則走更新課程
	const closeRef = useRef<HTMLDivElement>(null)
	const [unsavedChangesCheck, setUnsavedChangesCheck] = useState(true) // 是否檢查有未儲存的變更
	const invalidate = useInvalidate()

	const show = (theRecord?: TUserRecord) => () => {
		setRecord({ ...theRecord } as TUserRecord)
		setOpen(true)
	}

	const close = () => {
		if (!unsavedChangesCheck) {
			setOpen(false)
			return
		}

		// 與原本的值相比是否有變更
		const newValues = form.getFieldsValue()
		const fieldNames = Object.keys(newValues).filter(
			(fieldName) => !['files'].includes(fieldName)
		)
		const isEquals = fieldNames.every((fieldName) => {
			const originValue = record?.[fieldName as keyof typeof record]
			const newValue = newValues[fieldName]

			return isEqual(originValue, newValue)
		})

		if (!isEquals) {
			closeRef?.current?.click()
		} else {
			setOpen(false)
		}
	}

	const { mutate: create, isLoading: isLoadingCreate } = useCreate({
		resource,
		dataProviderName: resource !== 'users' ? 'power-course' : undefined,
		meta: {
			headers: { 'Content-Type': 'multipart/form-data;' },
		},
	})
	const { mutate: update, isLoading: isLoadingUpdate } = useUpdate({
		resource,
		dataProviderName: 'power-course',
		meta: {
			headers: { 'Content-Type': 'multipart/form-data;' },
		},
		invalidates: ['list'],
	})

	const handleSave = () => {
		form.validateFields().then(() => {
			const values = form.getFieldsValue()

			if (isUpdate) {
				const formData = toFormData(values)
				update(
					{
						id: record!.id,
						values: formData,
					},
					{
						onSuccess: () => {
							if (record) {
								setRecord({ ...(record as TUserRecord) })
							}
							setUnsavedChangesCheck(false)
							invalidate({
								resource: 'users',
								invalidates: ['list'],
							})
						},
					}
				)
			} else {
				const formData = toFormData({
					...values,
					is_teacher: 'yes',
				})

				create(
					{
						values: formData,
					},
					{
						onSuccess: () => {
							setOpen(false)
							form.resetFields()
							if (record) {
								setRecord({ ...(record as TUserRecord) })
							}
							setUnsavedChangesCheck(false)
						},
					}
				)
			}
		})
	}

	useEffect(() => {
		if (record?.id && open) {
			form.setFieldsValue(record)
		} else {
			form.resetFields()
		}
	}, [record, open])

	useEffect(() => {
		if (users && open) {
			const findUser = users.find((user) => user.id === record?.id)
			form.setFieldsValue(findUser)
		}
	}, [users, open])

	const mergedDrawerProps: DrawerProps = {
		title: `${isUpdate ? '編輯' : '新增'}講師`,
		forceRender: false,
		push: false,
		onClose: close,
		open,
		width: '50%',
		extra: (
			<div className="flex">
				<Popconfirm
					title="你儲存了嗎?"
					description="確認關閉後，你的編輯可能會遺失，請確認操作"
					placement="leftTop"
					okText="確認關閉"
					cancelText="取消"
					onConfirm={() => {
						setOpen(false)
					}}
				>
					<p ref={closeRef} className="">
						&nbsp;
					</p>
				</Popconfirm>
				<Button
					type="primary"
					onClick={handleSave}
					loading={isUpdate ? isLoadingUpdate : isLoadingCreate}
				>
					儲存
				</Button>
			</div>
		),
		...drawerProps,
	}

	return {
		open,
		setOpen,
		show,
		close,
		drawerProps: mergedDrawerProps,
	}
}
