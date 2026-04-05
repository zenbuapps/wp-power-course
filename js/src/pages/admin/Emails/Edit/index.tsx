import { Edit, useForm } from '@refinedev/antd'
import { useParsed, HttpError } from '@refinedev/core'
import { Switch, Form, Empty, Input } from 'antd'
import { JsonToMjml, IBlockData } from 'j7-easy-email-core'
import mjml2html from 'mjml-browser'

import { SendCondition } from '@/components/emails'
import type { TEmailRecord, TFormValues } from '@/pages/admin/Emails/types'

import EmailEditor from './EmailEditor'

const { Item } = Form

const EmailsEdit = () => {
	const { id } = useParsed()

	// 初始化資料
	const formReturn = useForm<TEmailRecord, HttpError, TFormValues>({
		action: 'edit',
		resource: 'emails',
		dataProviderName: 'power-email',
		id,
		redirect: false,
		invalidates: ['list', 'detail'],
		warnWhenUnsavedChanges: true,
	})
	const { formProps, form, saveButtonProps, mutation, query, onFinish } =
		formReturn
	const record = query?.data?.data
	const watchStatus = Form.useWatch(['status'], form)

	if (!record && query?.isSuccess) {
		return <Empty className="mt-[10rem]" description="找不到 Email" />
	}
	const { name = '' } = record || {}

	const handleSubmit = (values: TFormValues) => {
		// 要存的時候才將 json 轉成 html
		onFinish({
			...values,
			short_description: JSON.stringify(values.short_description),
			description: mjml2html(
				JsonToMjml({
					data: values.short_description as IBlockData<any, any>,
					mode: 'production',
					context: values.short_description as IBlockData<any, any>,
				}),
				{
					minify: true,
				}
			)?.html,
		} as TFormValues)
	}

	return (
		<div className="sticky-card-actions">
			<Edit
				resource="emails"
				dataProviderName="power-email"
				recordItemId={id}
				headerButtons={() => null}
				title={
					<>
						《編輯》 {name} <span className="text-gray-400 text-xs">#{id}</span>
					</>
				}
				saveButtonProps={{
					...saveButtonProps,
					children: '儲存 Email',
					icon: null,
					loading: mutation?.isLoading,
				}}
				footerButtons={({ defaultButtons }) => (
					<>
						<Switch
							className="mr-4"
							checkedChildren="啟用"
							unCheckedChildren="停用"
							value={watchStatus === 'publish'}
							onChange={(checked) => {
								form.setFieldValue(['status'], checked ? 'publish' : 'draft')
							}}
						/>
						{defaultButtons}
					</>
				)}
				isLoading={query?.isLoading}
			>
				<Form {...formProps} layout="vertical" onFinish={handleSubmit}>
					<div className="grid grid-cols-2 gap-4">
						<Item
							label="Email 名稱"
							name={['name']}
							tooltip="僅用於內部管理識別用，不會寄送給用戶"
							rules={[{ required: true, message: '請輸入 Email 名稱' }]}
						>
							<Input allowClear />
						</Item>
						<Item
							label="Email 主旨"
							name={['subject']}
							tooltip="信件主旨，會寄送給用戶"
						>
							<Input allowClear />
						</Item>
					</div>
					<Item name={['status']} hidden />
					{/* 存 json ， 才不會跑版 */}
					<Item hidden name={['short_description']} />

					<SendCondition email_ids={[id] as string[]} />

					<EmailEditor {...formReturn} />
				</Form>
			</Edit>
		</div>
	)
}
export default EmailsEdit
