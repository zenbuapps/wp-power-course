import { Edit, useForm } from '@refinedev/antd'
import { useParsed, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
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
		return (
			<Empty
				className="mt-[10rem]"
				description={__('Email not found', 'power-course')}
			/>
		)
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
