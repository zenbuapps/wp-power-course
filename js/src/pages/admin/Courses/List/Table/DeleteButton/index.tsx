import { DeleteOutlined } from '@ant-design/icons'
import { useModal } from '@refinedev/antd'
import { useDeleteMany } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Button, Alert, Modal, Input } from 'antd'
import { trim } from 'lodash-es'
import { memo, useState } from 'react'

const DeleteButton = ({
	selectedRowKeys,
	setSelectedRowKeys,
}: {
	selectedRowKeys: React.Key[]
	setSelectedRowKeys: React.Dispatch<React.SetStateAction<React.Key[]>>
}) => {
	const { show, modalProps, close } = useModal()
	const [value, setValue] = useState('')
	const { mutate: deleteMany, isLoading: isDeleting } = useDeleteMany()

	// 確認用密語（需使用者輸入才可刪除），避免誤刪
	const CONFIRM_WORD = __(
		'Yes, nothing can stop me, I want to delete these courses',
		'power-course'
	)

	return (
		<>
			<Button
				type="primary"
				danger
				icon={<DeleteOutlined />}
				onClick={show}
				disabled={!selectedRowKeys.length}
			>
				{__('Bulk delete courses', 'power-course')}
				{selectedRowKeys.length ? ` (${selectedRowKeys.length})` : ''}
			</Button>

			<Modal
				{...modalProps}
				title={sprintf(
					// translators: %s: 以逗號分隔的課程 ID 列表，例如 "#12, #34"
					__('Delete courses %s', 'power-course'),
					selectedRowKeys.map((id) => `#${id}`).join(', ')
				)}
				centered
				okButtonProps={{
					danger: true,
					disabled: trim(value) !== CONFIRM_WORD,
				}}
				okText={__('I understand the impact, confirm delete', 'power-course')}
				cancelText={__('Cancel', 'power-course')}
				onOk={() => {
					deleteMany(
						{
							resource: 'courses',
							dataProviderName: 'power-course',
							ids: selectedRowKeys as string[],
							mutationMode: 'optimistic',
							successNotification: (data, ids, resource) => {
								return {
									message: sprintf(
										// translators: %s: 以逗號分隔的課程 ID 列表
										__('Courses %s deleted successfully', 'power-course'),
										ids?.map((id) => `#${id}`).join(', ')
									),
									type: 'success',
								}
							},
							errorNotification: (data, ids, resource) => {
								return {
									message: __(
										'Oops, something went wrong. Please try again',
										'power-course'
									),
									type: 'error',
								}
							},
						},
						{
							onSuccess: () => {
								close()
								setSelectedRowKeys([])
							},
						}
					)
				}}
				confirmLoading={isDeleting}
			>
				<Alert
					message={__('Dangerous operation', 'power-course')}
					className="mb-2"
					description={
						<>
							<p>{__('Deleting courses will affect:', 'power-course')}</p>
							<ol className="pl-6">
								<li>
									{__(
										'Users who purchased the courses will no longer have access',
										'power-course'
									)}
								</li>
								<li>
									{__(
										'Existing user watch history will be deleted',
										'power-course'
									)}
								</li>
								<li>
									{__(
										'User comments and reviews on courses will be deleted',
										'power-course'
									)}
								</li>
								<li>
									{__('Course chapters will also be deleted', 'power-course')}
								</li>
								<li>
									{__(
										'Products linked to the courses will no longer be linked',
										'power-course'
									)}
								</li>
							</ol>
						</>
					}
					type="error"
					showIcon
				/>
				<p className="mb-2">
					{__('Are you sure you want to do this?', 'power-course')}{' '}
					{__(
						'If you understand the impact of deleting courses and still want to delete them, please type the following in the input box below:',
						'power-course'
					)}{' '}
					<b className="italic">{CONFIRM_WORD}</b>{' '}
				</p>
				<Input
					allowClear
					value={value}
					onChange={(e) => setValue(e.target.value)}
					placeholder={__('Please enter the text above', 'power-course')}
					className="mb-2"
				/>
			</Modal>
		</>
	)
}

export default memo(DeleteButton)
