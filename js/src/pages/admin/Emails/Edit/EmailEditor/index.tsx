import type { UseFormReturnType } from '@refinedev/antd'
import { useApiUrl, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Alert, type FormInstance } from 'antd'
import type { FormApi, FormState } from 'final-form'
import { BlockManager, BasicType, IBlockData } from 'j7-easy-email-core'
import { IEmailTemplate } from 'j7-easy-email-editor'
import React, { memo, lazy, Suspense } from 'react'

import { useEnv } from '@/hooks'
import type { TEmailRecord, TFormValues } from '@/pages/admin/Emails/types'
import { tryParseEmailContent } from '@/pages/admin/Emails/utils'

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
							<StandardLayout showSourceCode={false}>
								<EmailEditor />
							</StandardLayout>
						</>
					)
				}}
			</EmailEditorProvider>
		</Suspense>
	)
}

export default memo(CustomEmailEditor)
