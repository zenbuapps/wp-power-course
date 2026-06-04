import { EditOutlined } from '@ant-design/icons'
import {
	useApiUrl,
	useCustomMutation,
	useInvalidate,
	useOne,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Button, Form, message, Space, Spin } from 'antd'
import { SimpleModal } from 'antd-toolkit'
import { UserName } from 'antd-toolkit/wp'
import { useAtom } from 'jotai'
import { memo, useEffect, useState } from 'react'

import { RoleGate } from '@/components/RoleGate'
import { TUserDetail, TUserInfo } from '@/components/user/types'
import { SITE_URL } from '@/utils/env'

import { studentEditModalAtom } from '../UserTable/atom'

import { Detail } from './Detail'
import { IsEditingContext, RecordContext } from './hooks'

/** 表單值型別：扁平基本欄位 + 巢狀 billing/shipping + other_meta_data 陣列 */
type TFormValues = {
	user_email?: string
	display_name?: string
	first_name?: string
	last_name?: string
	role?: string
	user_birthday?: string
	description?: string
	user_pass?: string
	/** 僅前端用於密碼雙確認，儲存前移除不送出 */
	user_pass_confirm?: string
	billing?: Partial<TUserInfo>
	shipping?: Partial<TUserInfo>
	other_meta_data?: {
		umeta_id?: string
		meta_key?: string
		meta_value?: string
	}[]
}

/**
 * 從學員詳細資料推導表單初始值
 *
 * 載入填表與「取消編輯還原」共用。基本欄位扁平讀取，billing/shipping 以巢狀物件
 * 帶入（編輯欄位 name 為 `[type, field]`），other_meta_data 以陣列帶入。
 */
const detailToFormValues = (detail: TUserDetail): TFormValues => ({
	user_email: detail.user_email ?? detail.email,
	display_name: detail.display_name,
	first_name: detail.first_name,
	last_name: detail.last_name,
	role: detail.role,
	user_birthday: detail.user_birthday,
	description: detail.description,
	billing: detail.billing,
	shipping: detail.shipping,
	other_meta_data: detail.other_meta_data,
})

/**
 * 學員快速編輯 Modal
 *
 * 透過 jotai atom 控制開關與當前編輯的 user_id，以 antd-toolkit 的 SimpleModal（寬 1280）呈現。
 * 內容對齊 power-shop User Edit 雙欄卡片：左欄消費數據 + 用戶資料 Tabs（基本資料 / 自動填入 /
 * 其他欄位），右欄聯絡註記 + 購物車 + 最近訂單。子區塊透過 RecordContext / IsEditingContext 取資料。
 * 預設唯讀檢視，點「編輯用戶」切換為編輯模式（inline view↔edit toggle）。
 * 所有 HTTP 請求皆透過 Refine hooks，所有可見字串皆走 i18n。
 */
const StudentEditModalComponent: React.FC = () => {
	const [{ user_id, open }, setEditModal] = useAtom(studentEditModalAtom)
	const [form] = Form.useForm<TFormValues>()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	/** 是否處於編輯模式（預設唯讀檢視） */
	const [isEditing, setIsEditing] = useState(false)

	const enabled = open && !!user_id

	// 載入使用者詳細資料（已含後端擴充的統計 / billing / cart / recent_orders / contact_remarks 等欄位）
	const { data: userData, isFetching: isUserFetching } = useOne<TUserDetail>({
		resource: 'users',
		id: user_id,
		dataProviderName: 'power-course',
		queryOptions: {
			enabled,
		},
	})

	const detail = userData?.data

	// 儲存 mutation
	const { mutate: save, isLoading: isSaving } = useCustomMutation()

	// 載入資料後填入表單
	useEffect(() => {
		if (open && detail) {
			form.setFieldsValue(detailToFormValues(detail))
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [userData?.data, open])

	const handleClose = () => {
		setEditModal({ open: false })
		setIsEditing(false)
		form.resetFields()
	}

	/** 取消編輯：退回檢視模式並把表單還原為載入值 */
	const handleCancelEdit = () => {
		form.resetFields()
		if (detail) {
			form.setFieldsValue(detailToFormValues(detail))
		}
		setIsEditing(false)
	}

	const handleSave = () => {
		if (!user_id) return
		form.validateFields().then(() => {
			const values = form.getFieldsValue(true) as TFormValues

			// 將 billing / shipping 巢狀物件攤平成 billing_{field} / shipping_{field}（移植 power-shop handleOnFinish）
			// user_pass_confirm 僅前端密碼雙確認用，不送後端。
			const { billing, shipping, user_pass_confirm: _confirm, ...rest } = values
			const payload: Record<string, unknown> = { ...rest }
			;(
				[
					['billing', billing],
					['shipping', shipping],
				] as const
			).forEach(([type, info]) => {
				if (info) {
					Object.entries(info).forEach(([field, value]) => {
						payload[`${type}_${field}`] = value ?? ''
					})
				}
			})

			// 空密碼不送出（代表不變更）
			if (!payload.user_pass) {
				delete payload.user_pass
			}

			save(
				{
					url: `${apiUrl}/users/${user_id}`,
					method: 'post',
					values: payload,
					config: {
						headers: {
							'Content-Type': 'multipart/form-data;',
						},
					},
				},
				{
					onSuccess: () => {
						message.success({
							content: __('Modified successfully', 'power-course'),
							key: 'student-edit',
						})
						// 清空密碼欄位並退回檢視模式
						form.setFieldsValue({
							user_pass: undefined,
							user_pass_confirm: undefined,
						})
						setIsEditing(false)
						invalidate({
							resource: 'users',
							dataProviderName: 'power-course',
							invalidates: ['list', 'detail'],
						})
						invalidate({
							resource: 'students',
							dataProviderName: 'power-course',
							invalidates: ['list'],
						})
					},
					onError: (error) => {
						message.error({
							content:
								(error as { message?: string })?.message ||
								__('Modification failed', 'power-course'),
							key: 'student-edit',
						})
					},
				}
			)
		})
	}

	return (
		<SimpleModal
			width={1600}
			className="student-edit-modal"
			opacity={open ? 1 : 0}
			pointerEvents={open ? 'auto' : 'none'}
			destroyOnHidden
			onCancel={handleClose}
			title={
				<div className="flex items-baseline gap-2">
					<span>{__('Edit student', 'power-course')}</span>
					{!!user_id && (
						<span className="text-gray-400 text-xs">#{user_id}</span>
					)}
				</div>
			}
			footer={
				isEditing ? (
					<Space.Compact>
						<Button onClick={handleCancelEdit}>
							{__('Cancel', 'power-course')}
						</Button>
						<Button type="primary" loading={isSaving} onClick={handleSave}>
							{__('Save', 'power-course')}
						</Button>
					</Space.Compact>
				) : (
					<Button
						type="primary"
						icon={<EditOutlined />}
						onClick={() => setIsEditing(true)}
					>
						{__('Edit user', 'power-course')}
					</Button>
				)
			}
		>
			<IsEditingContext.Provider value={isEditing}>
				<RecordContext.Provider value={detail}>
					<Spin spinning={isUserFetching}>
						{/* header row：左側頭像 + 顯示名稱 + #id + email；右側前往傳統用戶編輯介面。不受 view↔edit 切換影響。 */}
						<div className="flex justify-between items-center py-2 mb-4">
							<div>
								{detail && (
									<UserName
										record={{
											display_name: detail.display_name ?? '',
											user_email: detail.email ?? '',
											id: detail.id ?? '',
											user_avatar_url: detail.user_avatar_url ?? '',
										}}
									/>
								)}
							</div>
							{/* F5：「前往傳統用戶編輯介面」按鈕僅 Administrator 可見 */}
							<RoleGate capability="admin">
								<Button
									type="default"
									// B4：edit_url 為空時 fallback 以 SITE_URL 組原生 user-edit.php 絕對網址，
									// 避免 href 為 undefined 時 antd 渲染成 <button> 而點擊無跳轉。
									href={
										detail?.edit_url ||
										(user_id
											? `${SITE_URL}/wp-admin/user-edit.php?user_id=${user_id}`
											: undefined)
									}
									target="_blank"
									rel="noopener noreferrer"
								>
									{__('Go to legacy user edit interface', 'power-course')}
								</Button>
							</RoleGate>
						</div>
						<Form<TFormValues> form={form} layout="vertical">
							<Detail />
						</Form>
					</Spin>
				</RecordContext.Provider>
			</IsEditingContext.Provider>
		</SimpleModal>
	)
}

export const StudentEditModal = memo(StudentEditModalComponent)
