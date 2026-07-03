import { Edit, useForm } from '@refinedev/antd'
import { useParsed, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Switch, Form, Empty, Input, message } from 'antd'
import { JsonToMjml } from 'j7-easy-email-core'
import mjml2html from 'mjml-browser'

import { SendCondition } from '@/components/emails'
import type { TEmailRecord, TFormValues } from '@/pages/admin/Emails/types'
import { tryParseEmailContent } from '@/pages/admin/Emails/utils'

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
		return (
			<Empty
				className="mt-[10rem]"
				description={__('Email not found', 'power-course')}
			/>
		)
	}
	const { name = '' } = record || {}

	const handleSubmit = (values: TFormValues) => {
		// 先還原為物件再「唯一一次」stringify：
		// 編輯器 lazy chunk 尚未載入完時，short_description 仍是 DB 取回的字串，
		// 直接 JSON.stringify(字串) 會造成雙重編碼，下次載入 parse 出字串 → 內容變空白
		const content = tryParseEmailContent(values.short_description)
		if (!content) {
			message.error(
				__(
					'Email content is invalid. Save aborted to prevent overwriting existing content.',
					'power-course'
				)
			)
			return
		}

		// 要存的時候才將 json 轉成 html；轉換失敗時中止儲存，避免把壞資料寫進 DB
		let html = ''
		try {
			html = mjml2html(
				JsonToMjml({
					data: content,
					mode: 'production',
					context: content,
				}),
				{
					minify: true,
				}
			)?.html
		} catch (error) {
			console.error('JsonToMjml/mjml2html error: ', error)
			message.error(
				__(
					'Failed to render email HTML. Save aborted to prevent data loss.',
					'power-course'
				)
			)
			return
		}

		onFinish({
			...values,
			short_description: JSON.stringify(content),
			description: html,
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
						{`${__('Edit', 'power-course')}: ${name}`}{' '}
						<span className="text-gray-400 text-xs">#{id}</span>
					</>
				}
				saveButtonProps={{
					...saveButtonProps,
					children: __('Save email', 'power-course'),
					icon: null,
					loading: mutation?.isLoading,
				}}
				footerButtons={({ defaultButtons }) => (
					<>
						<Switch
							className="mr-4"
							checkedChildren={__('Enable', 'power-course')}
							unCheckedChildren={__('Disable', 'power-course')}
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
							label={__('Email name', 'power-course')}
							name={['name']}
							tooltip={__(
								'For internal management only, will not be sent to users',
								'power-course'
							)}
							rules={[
								{
									required: true,
									message: __('Please enter email name', 'power-course'),
								},
							]}
						>
							<Input allowClear />
						</Item>
						<Item
							label={__('Email subject', 'power-course')}
							name={['subject']}
							tooltip={__(
								'Email subject, will be sent to users',
								'power-course'
							)}
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
