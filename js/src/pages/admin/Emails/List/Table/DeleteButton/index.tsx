import { useDeleteMany } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { memo } from 'react'

import { PopconfirmDelete } from '@/components/general'

const DeleteButton = ({
	selectedRowKeys,
	setSelectedRowKeys,
}: {
	selectedRowKeys: React.Key[]
	setSelectedRowKeys: React.Dispatch<React.SetStateAction<React.Key[]>>
}) => {
	const { mutate: deleteMany, isLoading: isDeleting } = useDeleteMany()

	const handleDelete = () => {
		deleteMany(
			{
				resource: 'emails',
				dataProviderName: 'power-email',
				ids: selectedRowKeys as string[],
				mutationMode: 'optimistic',
				successNotification: (data, ids, resource) => {
					return {
						message: sprintf(
							// translators: %s: Email id 清單（以逗號分隔）
							__('Emails %s deleted successfully', 'power-course'),
							ids?.map((id) => `#${id}`).join(', ') ?? ''
						),
						type: 'success',
					}
				},
				errorNotification: (data, ids, resource) => {
					return {
						message: __(
							'Oops, something went wrong, please try again',
							'power-course'
						),
						type: 'error',
					}
				},
			},
			{
				onSuccess: () => {
					setSelectedRowKeys([])
				},
			}
		)
	}

	return (
		<>
			<PopconfirmDelete
				type="button"
				popconfirmProps={{
					title: __('Confirm to delete these emails?', 'power-course'),
					onConfirm: handleDelete,
				}}
				buttonProps={{
					children: selectedRowKeys.length
						? sprintf(
								// translators: %d: 選取的 Email 數量
								__('Bulk delete emails (%d)', 'power-course'),
								selectedRowKeys.length
							)
						: __('Bulk delete emails', 'power-course'),
					disabled: !selectedRowKeys.length,
					loading: isDeleting,
				}}
			/>
		</>
	)
}

export default memo(DeleteButton)
