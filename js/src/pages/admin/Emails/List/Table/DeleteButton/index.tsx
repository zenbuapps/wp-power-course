import { useDeleteMany } from '@refinedev/core'
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
						message: ` Email  ${ids?.map((id) => `#${id}`).join(', ')} 已刪除成功`,
						type: 'success',
					}
				},
				errorNotification: (data, ids, resource) => {
					return {
						message: 'OOPS，出錯了，請在試一次',
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
					title: '確認刪除這些 Email 嗎',
					onConfirm: handleDelete,
				}}
				buttonProps={{
					children: `批次刪除 Email
						${selectedRowKeys.length ? ` (${selectedRowKeys.length})` : ''}`,
					disabled: !selectedRowKeys.length,
					loading: isDeleting,
				}}
			/>
		</>
	)
}

export default memo(DeleteButton)
