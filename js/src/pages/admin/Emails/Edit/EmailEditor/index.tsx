import type { UseFormReturnType } from '@refinedev/antd'
import { useApiUrl, HttpError } from '@refinedev/core'
import type { FormInstance } from 'antd'
import type { FormApi, FormState } from 'final-form'
import { BlockManager, BasicType, IBlockData } from 'j7-easy-email-core'
import { IEmailTemplate } from 'j7-easy-email-editor'
import parseJson from 'parse-json'
import React, { memo, lazy, Suspense } from 'react'

import { useEnv } from '@/hooks'
import type { TEmailRecord, TFormValues } from '@/pages/admin/Emails/types'

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

function getInitContent(form: FormInstance, defaultBlock: IBlockData) {
	// 有可能是 物件 也可能是 stringfy 的 字串 (初始化時)
	const initContentString = form.getFieldValue(['short_description'])

	let initContent = defaultBlock
	if (initContentString && 'string' === typeof initContentString) {
		try {
			initContent = parseJson(initContentString) as any
		} catch (error) {
			console.log('parse JSON error: ', error)
		}
	}

	if ('object' === typeof initContentString) {
		initContent = initContentString
	}
	return initContent
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

	const initialValues: IEmailTemplate = query?.isSuccess
		? {
				subject: form.getFieldValue(['subject']),
				subTitle: '',
				content: getInitContent(form, initBlock),
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
					form.setFieldValue(['short_description'], formState?.values?.content)
					return (
						<>
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
