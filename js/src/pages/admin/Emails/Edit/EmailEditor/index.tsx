import { MenuFoldOutlined, MenuUnfoldOutlined } from '@ant-design/icons'
import type { UseFormReturnType } from '@refinedev/antd'
import { useApiUrl, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Alert, Button, type FormInstance } from 'antd'
import type { FormApi, FormState } from 'final-form'
import { useAtomValue } from 'jotai'
import { BlockManager, BasicType, IBlockData } from 'j7-easy-email-core'
import { IEmailTemplate } from 'j7-easy-email-editor'
import React, { memo, lazy, Suspense, useState, useCallback } from 'react'

import { useEnv } from '@/hooks'
import type { TEmailRecord, TFormValues } from '@/pages/admin/Emails/types'
import { tryParseEmailContent } from '@/pages/admin/Emails/utils'

import { immersiveAtom } from '../immersive/atom'

const EmailEditor = lazy(() =>
	Promise.all([
		import('j7-easy-email-editor'),
		import('j7-easy-email-editor/lib/style.css'),
		import('j7-easy-email-extensions/lib/style.css'),

		// theme, If you need to change the theme, you can make a duplicate in https://arco.design/themes/design/1799/setting/base/Color
		import('@arco-themes/react-easy-email-theme/css/arco.css'),
	]).then(([module]) => ({
		default: module.EmailEditor,
	}))
)

const EmailEditorProvider = lazy(() =>
	import('j7-easy-email-editor').then((module) => ({
		default: module.EmailEditorProvider,
	}))
)

const StandardLayout = lazy(() =>
	import('j7-easy-email-extensions').then((module) => ({
		default: module.StandardLayout,
	}))
)

const initBlock = BlockManager.getBlockByType(BasicType.PAGE)!.create({})

/**
 * 取得編輯器初始內容
 *
 * @return content = 解析後的內容（失敗時為預設空白 page）；
 *         parseFailed = DB 有內容但解析失敗（呼叫端須阻止靜默覆寫，避免下次儲存清空內容）
 */
function getInitContent(
	form: FormInstance,
	defaultBlock: IBlockData
): { content: IBlockData; parseFailed: boolean } {
	// 有可能是 物件 也可能是 stringify 的 字串 (初始化時)
	const raw = form.getFieldValue(['short_description'])

	// 全新信件（無內容）：使用預設空白 page
	if (!raw || ('string' === typeof raw && raw.trim() === '')) {
		return { content: defaultBlock, parseFailed: false }
	}

	const parsed = tryParseEmailContent(raw)
	if (!parsed) {
		return { content: defaultBlock, parseFailed: true }
	}
	return { content: parsed, parseFailed: false }
}

/**
 * TODO 模板套用
 */

/**
 * Easy Email Editor
 * @see https://github.com/zalify/easy-email-editor
 * @param {UseFormReturnType} props - UseFormReturnType
 * @return {JSX.Element}
 */
const CustomEmailEditor = (
	props: UseFormReturnType<
		TEmailRecord,
		HttpError,
		TFormValues,
		TEmailRecord,
		TEmailRecord,
		HttpError
	>
) => {
	const { form, query } = props

	// 沉浸狀態（由 immersiveAtom 提供，與 Edit 頁 headerButtons 共用同一來源）
	const immersive = useAtomValue(immersiveAtom)

	// 左 / 右面板收合狀態（純編輯器內部 UI state，收合態 class 交給 CSS 收合面板）
	const [collapseLeft, setCollapseLeft] = useState(false)
	const [collapseRight, setCollapseRight] = useState(false)

	const toggleLeft = useCallback(() => {
		setCollapseLeft((prev) => !prev)
	}, [])
	const toggleRight = useCallback(() => {
		setCollapseRight((prev) => !prev)
	}, [])

	// 組合 wrap class：沉浸 / 左收合 / 右收合（對齊共享 DOM 契約）
	const wrapClassName = [
		'pc-email-editor-wrap',
		immersive && 'pc-email-editor-wrap--immersive',
		collapseLeft && 'pc-email-editor-wrap--collapse-left',
		collapseRight && 'pc-email-editor-wrap--collapse-right',
	]
		.filter(Boolean)
		.join(' ')

	const { content: initContent, parseFailed } = query?.isSuccess
		? getInitContent(form, initBlock)
		: { content: initBlock, parseFailed: false }

	const initialValues: IEmailTemplate = query?.isSuccess
		? {
				subject: form.getFieldValue(['subject']),
				subTitle: '',
				content: initContent,
			}
		: {
				subject: '',
				subTitle: '',
				content: initBlock,
			}

	const apiUrl = useApiUrl('power-course')

	const { AXIOS_INSTANCE } = useEnv()

	return (
		<Suspense
			fallback={
				<div className="w-full h-full flex justify-center items-center">
					Loading...
				</div>
			}
		>
			<EmailEditorProvider
				data={initialValues}
				dashed={false}
				onUploadImage={async (file) => {
					const res = await AXIOS_INSTANCE.post(
						`${apiUrl}/upload`,
						{
							files: file,
						},
						{
							headers: {
								'Content-Type': 'multipart/form-data;',
							},
						}
					)
					return res?.data?.data?.url
				}}
			>
				{(
					formState: FormState<IEmailTemplate>,
					helper: FormApi<IEmailTemplate, Partial<IEmailTemplate>>
				) => {
					// parse 失敗時，在用戶實際改動編輯器（dirty）之前不要覆寫 form 欄位，
					// 保住 DB 內既有字串，避免下一次儲存把空白內容寫回 → 資料永久遺失
					if (!parseFailed || formState?.dirty) {
						form.setFieldValue(
							['short_description'],
							formState?.values?.content
						)
					}
					return (
						<>
							{parseFailed && !formState?.dirty && (
								<Alert
									className="mb-4"
									type="error"
									showIcon
									message={__(
										'Existing email content could not be parsed',
										'power-course'
									)}
									description={__(
										'The saved content is corrupted and cannot be displayed. Saving now will NOT overwrite the original data unless you edit the content below. Please contact support if you need to recover it.',
										'power-course'
									)}
								/>
							)}
							<div
								data-testid="email-editor-wrap"
								className={wrapClassName}
							>
								<div className="pc-email-editor-wrap__toolbar">
									<Button
										data-testid="collapse-left"
										size="small"
										type="text"
										aria-label={__('Collapse left panel', 'power-course')}
										title={__('Collapse left panel', 'power-course')}
										icon={
											collapseLeft ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />
										}
										onClick={toggleLeft}
									/>
									<Button
										data-testid="collapse-right"
										size="small"
										type="text"
										aria-label={__('Collapse right panel', 'power-course')}
										title={__('Collapse right panel', 'power-course')}
										icon={
											collapseRight ? (
												<MenuFoldOutlined />
											) : (
												<MenuUnfoldOutlined />
											)
										}
										onClick={toggleRight}
									/>
								</div>
								<StandardLayout showSourceCode={false}>
									<EmailEditor />
								</StandardLayout>
							</div>
						</>
					)
				}}
			</EmailEditorProvider>
		</Suspense>
	)
}

export default memo(CustomEmailEditor)
